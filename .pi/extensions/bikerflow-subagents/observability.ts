/**
 * Observability Module — Trace Logger + Log Sanitizer
 *
 * Writes sanitized JSONL trace logs from `pi --mode json` event streams.
 * Each subagent run produces one trace file at `docs/agents/logs/<date>-<persona>-<task-id>.jsonl`.
 *
 * Usage:
 *   const logger = TraceLogger.start("planner", "US-01 trip tracking", "US-01", logsDir);
 *   // feed events from JSON stream:
 *   logger.onEvent(rawEvent);
 *   // when subagent process exits:
 *   const summary = logger.finalize(exitCode, stopReason, finalOutput);
 */

import * as fs from "node:fs";
import * as path from "node:path";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/** A single JSONL line written to the trace log file. */
export interface TraceEvent {
	ts: string;
	event: string;
	[key: string]: unknown;
}

/** Aggregate stats accumulated over a single subagent run. */
export interface TraceSummary {
	persona: string;
	task: string;
	taskId: string;
	traceLog: string;
	startedAt: string;
	completedAt: string;
	totalDurationMs: number;
	totalTurns: number;
	totalInputTokens: number;
	totalOutputTokens: number;
	totalCacheReadTokens: number;
	totalCacheWriteTokens: number;
	totalCost: number;
	stopReason: string;
	exitCode: number;
	toolCalls: number;
	toolCallsFailed: number;
	outputArtifacts: string[];
}

// ---------------------------------------------------------------------------
// LogSanitizer
// ---------------------------------------------------------------------------

/**
 * Strips sensitive/large payloads from tool arguments and results
 * before writing to the trace log.
 *
 * Rules from the architecture plan:
 * - write tool:  Remove `content` from args (keep path + content length)
 * - edit tool:   Keep path, oldText/newText length only
 * - bash tool:   Keep command as-is; truncate result summary to 500 chars
 * - read tool:   Keep path, offset, limit (strip file contents from result)
 * - All others:  Pass through args as-is
 */
export class LogSanitizer {
	private static readonly MAX_RESULT_PREVIEW = 500;

	sanitizeArgs(toolName: string, args: Record<string, unknown>): Record<string, unknown> {
		switch (toolName) {
			case "write": {
				const { content, ...rest } = args;
				return {
					...rest,
					contentLength: typeof content === "string" ? content.length : undefined,
				};
			}
			case "edit": {
				return {
					path: args.path ?? args.file_path,
					oldTextLength: typeof args.oldText === "string" ? args.oldText.length : undefined,
					newTextLength: typeof args.newText === "string" ? args.newText.length : undefined,
				};
			}
			default:
				return args;
		}
	}

	sanitizeResult(toolName: string, result: unknown): unknown {
		if (result === null || result === undefined) return result;

		switch (toolName) {
			case "bash": {
				// result is typically { output, exitCode, ... } — truncate output
				if (typeof result === "object" && result !== null) {
					const r = result as Record<string, unknown>;
					const output = typeof r.output === "string" ? r.output : String(r.output ?? "");
					return {
						...r,
						output:
							output.length > LogSanitizer.MAX_RESULT_PREVIEW
								? output.slice(0, LogSanitizer.MAX_RESULT_PREVIEW) + "... [truncated]"
								: output,
					};
				}
				return result;
			}
			case "read": {
				// Strip file contents — keep metadata only
				if (typeof result === "object" && result !== null) {
					const r = result as Record<string, unknown>;
					return {
						path: r.path ?? r.file_path,
						offset: r.offset,
						limit: r.limit,
						contentLength: typeof r.content === "string" ? r.content.length : undefined,
						isError: r.isError,
					};
				}
				return result;
			}
			default:
				return result;
		}
	}
}

// ---------------------------------------------------------------------------
// TraceLogger
// ---------------------------------------------------------------------------

/**
 * Writes sanitized JSONL trace events to a log file for a single subagent run.
 *
 * Lifecycle:
 *   1. `TraceLogger.start(...)` — creates file, writes `agent_start` event
 *   2. `onEvent(raw)`           — called for each JSON line from the pi stream
 *   3. `finalize(...)`          — writes `agent_end` event, closes file, returns summary
 */
export class TraceLogger {
	private readonly _writeSync: (line: string) => void;
	private readonly sanitizer = new LogSanitizer();
	private readonly startedAt: number;
	private readonly filePath: string;
	private readonly persona: string;
	private readonly task: string;
	private readonly taskId: string;

	// Accumulated state
	private turnIndex = 0;
	private turnStartTime = 0;
	private toolStartTime = 0;
	private toolName = "";
	private totalInputTokens = 0;
	private totalOutputTokens = 0;
	private totalCacheReadTokens = 0;
	private totalCacheWriteTokens = 0;
	private totalCost = 0;
	private toolCallCount = 0;
	private toolFailCount = 0;
	private outputArtifacts: string[] = [];
	private finalized = false;

	private constructor(
		persona: string,
		task: string,
		taskId: string,
		logsDir: string,
	) {
		this.persona = persona;
		this.task = task;
		this.taskId = taskId;
		this.startedAt = Date.now();

		const date = new Date().toISOString().slice(0, 10);
		const safeTaskId = taskId.replace(/[^\w.-]/g, "_");
		const fileName = `${date}-${persona}-${safeTaskId}.jsonl`;
		this.filePath = path.join(logsDir, fileName);

		// Ensure directory exists and create file synchronously
		const dir = path.dirname(this.filePath);
		fs.mkdirSync(dir, { recursive: true });
		fs.writeFileSync(this.filePath, "");

		// Use sync writes to ensure trace events are flushed before finalize() returns
		this._writeSync = (line: string) => {
			try { fs.appendFileSync(this.filePath, line); } catch { /* non-blocking */ }
		};

		// Write agent_start event
		this.writeEvent({
			event: "agent_start",
			persona,
			task,
			taskId,
		});
	}

	/**
	 * Create and start a new TraceLogger.
	 *
	 * @param persona  Agent name (e.g. "planner", "tester")
	 * @param task     Task description
	 * @param taskId   Short task identifier (e.g. "US-01")
	 * @param logsDir  Directory for trace log files (e.g. "docs/agents/logs")
	 */
	static start(persona: string, task: string, taskId: string, logsDir: string): TraceLogger {
		return new TraceLogger(persona, task, taskId, logsDir);
	}

	/** Full path to the trace log file. */
	get traceLogPath(): string {
		return this.filePath;
	}

	/**
	 * Process a single JSON event from the `pi --mode json` stream.
	 * Routes to the appropriate handler based on event type.
	 */
	onEvent(raw: Record<string, unknown>): void {
		if (this.finalized) return;

		const type = raw.type as string;
		if (!type) return;

		switch (type) {
			case "agent_start":
				// Already logged in constructor. Skip duplicate.
				break;

			case "agent_end":
				// Handled in finalize(). Don't double-log.
				break;

			case "turn_start":
				this.handleTurnStart();
				break;

			case "turn_end":
				this.handleTurnEnd(raw);
				break;

			case "message_start":
				this.handleMessageStart(raw);
				break;

			case "message_update":
				// High-frequency streaming deltas — only log a brief marker
				this.writeEvent({
					event: "message_update",
					turn: this.turnIndex,
					deltaType: (raw.assistantMessageEvent as Record<string, unknown>)?.type,
				});
				break;

			case "message_end":
				this.handleMessageEnd(raw);
				break;

			case "tool_execution_start":
				this.handleToolExecutionStart(raw);
				break;

			case "tool_execution_update":
				this.writeEvent({
					event: "tool_execution_update",
					toolCallId: raw.toolCallId,
					toolName: raw.toolName,
					turn: this.turnIndex,
				});
				break;

			case "tool_execution_end":
				this.handleToolExecutionEnd(raw);
				break;

			// Ignore session header, queue_update, compaction, auto_retry — not relevant to traces
			default:
				break;
		}
	}

	/**
	 * Finalize the trace log. Writes the `agent_end` event, closes the file,
	 * and returns the aggregate summary.
	 */
	finalize(exitCode: number, stopReason: string, finalOutput: string): TraceSummary {
		if (this.finalized) {
			return this.buildSummary(exitCode, stopReason);
		}
		this.finalized = true;

		const completedAt = Date.now();
		const totalDurationMs = completedAt - this.startedAt;

		// Extract output artifacts from write/edit tool calls we tracked
		const artifacts = this.deduplicateArtifacts();

		this.writeEvent({
			event: "agent_end",
			exitCode,
			stopReason,
			totalTurns: this.turnIndex,
			totalDurationMs,
			totalCost: this.roundCost(this.totalCost),
			totalInputTokens: this.totalInputTokens,
			totalOutputTokens: this.totalOutputTokens,
			output: this.truncate(finalOutput, 2000),
			outputArtifacts: artifacts,
		});

		return this.buildSummary(exitCode, stopReason, completedAt, totalDurationMs, artifacts);
	}

	/**
	 * Get current artifacts tracked so far (for pipeline hooks to inspect mid-run).
	 */
	getArtifacts(): readonly string[] {
		return this.outputArtifacts;
	}

	// -----------------------------------------------------------------------
	// Private handlers
	// -----------------------------------------------------------------------

	private handleTurnStart(): void {
		this.turnIndex++;
		this.turnStartTime = Date.now();
		this.writeEvent({
			event: "turn_start",
			turn: this.turnIndex,
		});
	}

	private handleTurnEnd(raw: Record<string, unknown>): void {
		const durationMs = this.turnStartTime ? Date.now() - this.turnStartTime : 0;
		this.writeEvent({
			event: "turn_end",
			turn: this.turnIndex,
			durationMs,
			toolResults: Array.isArray(raw.toolResults) ? raw.toolResults.length : 0,
		});
	}

	private handleMessageStart(raw: Record<string, unknown>): void {
		const msg = raw.message as Record<string, unknown> | undefined;
		this.writeEvent({
			event: "message_start",
			role: msg?.role,
			turn: this.turnIndex,
		});
	}

	private handleMessageEnd(raw: Record<string, unknown>): void {
		const msg = raw.message as Record<string, unknown> | undefined;
		if (!msg) return;

		let loggedTokens: undefined | Record<string, unknown>;

		if (msg.role === "assistant") {
			const usage = msg.usage as Record<string, unknown> | undefined;
			const rawCost = usage?.cost;
			// Cost can be a number (scalar) or an object { total: number }
			const costNum = typeof rawCost === "number"
				? rawCost
				: (rawCost as Record<string, unknown> | undefined)?.total as number;

			if (usage) {
				this.totalInputTokens += (usage.input as number) || 0;
				this.totalOutputTokens += (usage.output as number) || 0;
				this.totalCacheReadTokens += (usage.cacheRead as number) || 0;
				this.totalCacheWriteTokens += (usage.cacheWrite as number) || 0;

				this.totalCost += costNum || 0;

				loggedTokens = {
					input: usage.input || 0,
					output: usage.output || 0,
					cacheRead: usage.cacheRead || 0,
					cacheWrite: usage.cacheWrite || 0,
					cost: this.roundCost(costNum || 0),
				};
			}
		}

		this.writeEvent({
			event: "message_end",
			role: msg.role,
			turn: this.turnIndex,
			tokens: loggedTokens,
			stopReason: msg.stopReason,
		});
	}

	private handleToolExecutionStart(raw: Record<string, unknown>): void {
		const toolName = raw.toolName as string;
		const rawArgs = (raw.args as Record<string, unknown>) || {};
		this.toolName = toolName;
		this.toolStartTime = Date.now();
		this.toolCallCount++;

		// Track file artifacts from write/edit
		if (toolName === "write" || toolName === "edit") {
			const filePath = rawArgs.path || rawArgs.file_path;
			if (typeof filePath === "string") {
				this.outputArtifacts.push(filePath);
			}
		}

		this.writeEvent({
			event: "tool_execution_start",
			toolCallId: raw.toolCallId,
			tool: toolName,
			args: this.sanitizer.sanitizeArgs(toolName, rawArgs),
			turn: this.turnIndex,
		});
	}

	private handleToolExecutionEnd(raw: Record<string, unknown>): void {
		const toolName = (raw.toolName as string) || this.toolName;
		const isError = raw.isError as boolean;
		const durationMs = this.toolStartTime ? Date.now() - this.toolStartTime : 0;

		if (isError) this.toolFailCount++;

		const rawResult = raw.result;
		let resultSummary: unknown = rawResult;
		if (rawResult !== null && rawResult !== undefined) {
			resultSummary = this.sanitizer.sanitizeResult(toolName, rawResult);
		}

		this.writeEvent({
			event: "tool_execution_end",
			toolCallId: raw.toolCallId,
			tool: toolName,
			success: !isError,
			durationMs,
			resultSummary,
			turn: this.turnIndex,
		});
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private writeEvent(partial: Omit<TraceEvent, "ts">): void {
		if (this.finalized && partial.event !== "agent_end") return;

		const event: TraceEvent = {
			ts: new Date().toISOString(),
			...partial,
		};

		try {
			this._writeSync(JSON.stringify(event) + "\n");
		} catch {
			// Observability is non-blocking — swallow I/O errors
		}
	}

	private buildSummary(
		exitCode: number,
		stopReason: string,
		completedAt?: number,
		totalDurationMs?: number,
		artifacts?: string[],
	): TraceSummary {
		const endTs = completedAt ?? Date.now();
		return {
			persona: this.persona,
			task: this.task,
			taskId: this.taskId,
			traceLog: this.filePath,
			startedAt: new Date(this.startedAt).toISOString(),
			completedAt: new Date(endTs).toISOString(),
			totalDurationMs: totalDurationMs ?? endTs - this.startedAt,
			totalTurns: this.turnIndex,
			totalInputTokens: this.totalInputTokens,
			totalOutputTokens: this.totalOutputTokens,
			totalCacheReadTokens: this.totalCacheReadTokens,
			totalCacheWriteTokens: this.totalCacheWriteTokens,
			totalCost: this.roundCost(this.totalCost),
			stopReason,
			exitCode,
			toolCalls: this.toolCallCount,
			toolCallsFailed: this.toolFailCount,
			outputArtifacts: artifacts ?? this.deduplicateArtifacts(),
		};
	}

	private deduplicateArtifacts(): string[] {
		return [...new Set(this.outputArtifacts)];
	}

	private truncate(str: string, maxLen: number): string {
		if (str.length <= maxLen) return str;
		return str.slice(0, maxLen) + "... [truncated]";
	}

	private roundCost(n: number): number {
		return Math.round(n * 10000) / 10000; // 4 decimal places
	}
}
