/**
 * Subagent Spawning Module
 *
 * Spawns a `pi --mode json` subprocess for a given BikerFlow persona,
 * processes the JSON event stream, feeds events to the observability
 * layer (TraceLogger), and returns a structured result.
 *
 * Adapted from pi's `examples/extensions/subagent/index.ts` with:
 *   - Full observability integration (all event types → TraceLogger)
 *   - Project-local temp files (storage/framework/)
 *   - Structured SubagentResult with TraceSummary
 */

import { spawn } from "node:child_process";
import * as fs from "node:fs";
import * as os from "node:os";
import * as path from "node:path";
import type { Message } from "@mariozechner/pi-ai";
import type { AgentToolResult } from "@mariozechner/pi-coding-agent";
import { withFileMutationQueue } from "@mariozechner/pi-coding-agent";
import type { AgentConfig } from "./agents.js";
import { discoverAgents } from "./agents.js";
import type { TraceSummary } from "./observability.js";
import { TraceLogger } from "./observability.js";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface UsageStats {
	input: number;
	output: number;
	cacheRead: number;
	cacheWrite: number;
	cost: number;
	contextTokens: number;
	turns: number;
}

export interface SubagentResult {
	persona: string;
	task: string;
	taskId: string;
	exitCode: number;
	messages: Message[];
	stderr: string;
	usage: UsageStats;
	model?: string;
	stopReason?: string;
	errorMessage?: string;
	traceSummary: TraceSummary;
	finalOutput: string;
}

export interface SubagentConfig {
	/** Agent persona name (e.g. "planner", "tester") */
	persona: string;
	/** Task description / prompt for the subagent */
	task: string;
	/** Short task identifier for trace log naming (e.g. "US-01") */
	taskId: string;
	/** Working directory for the subprocess (typically the project root) */
	cwd: string;
	/** Directory for trace log files (e.g. "docs/agents/logs") */
	logsDir: string;
	/** Optional model override (overrides agent's default) */
	model?: string;
	/** Abort signal for Ctrl+C propagation */
	signal?: AbortSignal;
	/** Streaming callback for partial result updates */
	onUpdate?: (partial: AgentToolResult<SubagentResult>) => void;
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const TEMP_DIR_NAME = "pi-subagent-prompts";

// ---------------------------------------------------------------------------
// Pi invocation helper (copied from pi's subagent example)
// ---------------------------------------------------------------------------

/**
 * Determine how to invoke pi as a subprocess.
 *
 * If the current process is running via the pi binary (not generic node/bun),
 * reuse the same binary. Otherwise fall back to the `pi` CLI command.
 */
function getPiInvocation(args: string[]): { command: string; args: string[] } {
	const currentScript = process.argv[1];
	const isBunVirtualScript = currentScript?.startsWith("/$bunfs/root/");

	if (currentScript && !isBunVirtualScript && fs.existsSync(currentScript)) {
		return { command: process.execPath, args: [currentScript, ...args] };
	}

	const execName = path.basename(process.execPath).toLowerCase();
	const isGenericRuntime = /^(node|bun)(\.exe)?$/.test(execName);
	if (!isGenericRuntime) {
		return { command: process.execPath, args };
	}

	return { command: "pi", args };
}

// ---------------------------------------------------------------------------
// Temp file management (project-local)
// ---------------------------------------------------------------------------

/**
 * Write the system prompt to a temp file under `storage/framework/` in the
 * project directory. This keeps temp files local to the project for easier
 * debugging and ensures they get cleaned up.
 */
async function writePromptToTempFile(
	agentName: string,
	prompt: string,
	projectCwd: string,
): Promise<{ dir: string; filePath: string }> {
	const tmpDir = path.join(projectCwd, "storage", "framework", TEMP_DIR_NAME);
	fs.mkdirSync(tmpDir, { recursive: true });

	// Self-healing cleanup: remove stale prompt files older than 1 hour.
	// This prevents temp file accumulation if the parent process crashes
	// (SIGKILL, OOM) before the finally block can clean up.
	try {
		const now = Date.now();
		const ONE_HOUR = 60 * 60 * 1000;
		for (const entry of fs.readdirSync(tmpDir)) {
			const fullPath = path.join(tmpDir, entry);
			try {
				const stat = fs.statSync(fullPath);
				if (stat.isFile() && now - stat.mtimeMs > ONE_HOUR) {
					fs.unlinkSync(fullPath);
				}
			} catch {
				/* ignore individual file errors */
			}
		}
	} catch {
		/* non-blocking — cleanup is best-effort */
	}

	const safeName = agentName.replace(/[^\w.-]+/g, "_");
	const filePath = path.join(tmpDir, `prompt-${safeName}-${Date.now()}.md`);

	await withFileMutationQueue(filePath, async () => {
		await fs.promises.writeFile(filePath, prompt, { encoding: "utf-8", mode: 0o600 });
	});

	return { dir: tmpDir, filePath };
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function emptyUsage(): UsageStats {
	return {
		input: 0,
		output: 0,
		cacheRead: 0,
		cacheWrite: 0,
		cost: 0,
		contextTokens: 0,
		turns: 0,
	};
}

/**
 * Extract the last assistant text from a message array.
 */
function getFinalOutput(messages: Message[]): string {
	for (let i = messages.length - 1; i >= 0; i--) {
		const msg = messages[i];
		if (msg.role === "assistant") {
			for (const part of msg.content) {
				if (part.type === "text") return part.text;
			}
		}
	}
	return "";
}

// ---------------------------------------------------------------------------
// Core: runSubagent
// ---------------------------------------------------------------------------

/**
 * Spawn a `pi --mode json` subprocess for a given BikerFlow persona.
 *
 * Lifecycle:
 *   1. Discover agent config by persona name
 *   2. Build pi CLI arguments (model, tools, system prompt)
 *   3. Start TraceLogger for observability
 *   4. Spawn subprocess, process JSON stream
 *   5. On exit: finalize trace, return structured SubagentResult
 *
 * @param config - Subagent configuration
 * @returns Structured result including trace summary, messages, usage stats
 */
export async function runSubagent(config: SubagentConfig): Promise<SubagentResult> {
	const { persona, task, taskId, cwd, logsDir, model, signal, onUpdate } = config;

	// --- 1. Discover agent ---
	const agents = discoverAgents(cwd);
	const agent = agents.find((a) => a.name === persona);

	if (!agent) {
		const available = agents.map((a) => `"${a.name}"`).join(", ") || "none";
		const errorMsg = `Unknown agent: "${persona}". Available agents: ${available}.`;
		const now = new Date();
		return {
			persona,
			task,
			taskId,
			exitCode: 1,
			messages: [],
			stderr: errorMsg,
			usage: emptyUsage(),
			stopReason: "error",
			errorMessage: errorMsg,
			traceSummary: {
				persona,
				task,
				taskId,
				traceLog: "",
				startedAt: now.toISOString(),
				completedAt: now.toISOString(),
				totalDurationMs: 0,
				totalTurns: 0,
				totalInputTokens: 0,
				totalOutputTokens: 0,
				totalCacheReadTokens: 0,
				totalCacheWriteTokens: 0,
				totalCost: 0,
				stopReason: "error",
				exitCode: 1,
				toolCalls: 0,
				toolCallsFailed: 0,
				outputArtifacts: [],
			},
			finalOutput: "",
		};
	}

	// --- 2. Build CLI arguments ---
	const args: string[] = ["--mode", "json", "-p", "--no-session"];
	if (model) args.push("--model", model);
	else if (agent.model) args.push("--model", agent.model);
	if (agent.tools && agent.tools.length > 0) args.push("--tools", agent.tools.join(","));

	let tmpPromptPath: string | null = null;

	// Initialize mutable result state
	const currentResult: SubagentResult = {
		persona,
		task,
		taskId,
		exitCode: 0,
		messages: [],
		stderr: "",
		usage: emptyUsage(),
		model: model ?? agent.model,
		finalOutput: "",
		// traceSummary placeholder — filled after finalize()
		traceSummary: null as unknown as TraceSummary,
	};

	const emitUpdate = () => {
		if (onUpdate) {
			onUpdate({
				content: [
					{
						type: "text",
						text: currentResult.finalOutput || "(running...)",
					},
				],
				details: currentResult,
			});
		}
	};

	// --- 3. Start TraceLogger ---
	const traceLogger = TraceLogger.start(persona, task, taskId, logsDir);

	try {
		// Write system prompt to temp file
		if (agent.systemPrompt.trim()) {
			const tmp = await writePromptToTempFile(agent.name, agent.systemPrompt, cwd);
			tmpPromptPath = tmp.filePath;
			args.push("--append-system-prompt", tmpPromptPath);
		}

		args.push(`Task: ${task}`);

		// --- 4. Spawn subprocess ---
		let wasAborted = false;

		const exitCode = await new Promise<number>((resolve) => {
			const invocation = getPiInvocation(args);
			const proc = spawn(invocation.command, invocation.args, {
				cwd,
				shell: false,
				stdio: ["ignore", "pipe", "pipe"],
			});

			let stdoutBuffer = "";

			const processLine = (line: string) => {
				if (!line.trim()) return;
				let rawEvent: Record<string, unknown>;
				try {
					rawEvent = JSON.parse(line);
				} catch {
					return;
				}

				// Feed to observability (all event types)
				traceLogger.onEvent(rawEvent);

				// Extract structured data from key events
				if (rawEvent.type === "message_end" && rawEvent.message) {
					const msg = rawEvent.message as Message;
					currentResult.messages.push(msg);

					if (msg.role === "assistant") {
						currentResult.usage.turns++;
						const usage = msg.usage as Record<string, unknown> | undefined;
						if (usage) {
							currentResult.usage.input += (usage.input as number) || 0;
							currentResult.usage.output += (usage.output as number) || 0;
							currentResult.usage.cacheRead += (usage.cacheRead as number) || 0;
							currentResult.usage.cacheWrite += (usage.cacheWrite as number) || 0;
							currentResult.usage.contextTokens = (usage.totalTokens as number) || 0;
						}
						const rawCost = (msg.usage as Record<string, unknown>)?.cost;
						const costNum = typeof rawCost === "number"
							? rawCost
							: ((rawCost as Record<string, unknown> | undefined)?.total as number);
						if (costNum) {
							currentResult.usage.cost += costNum;
						}
						if (!currentResult.model && msg.model) currentResult.model = msg.model;
						if (msg.stopReason) currentResult.stopReason = msg.stopReason;
						if (msg.errorMessage) currentResult.errorMessage = msg.errorMessage;
					}

					// Update final output on each assistant message
					currentResult.finalOutput = getFinalOutput(currentResult.messages);
					emitUpdate();
				}

				if (rawEvent.type === "tool_result_end" && rawEvent.message) {
					currentResult.messages.push(rawEvent.message as Message);
					emitUpdate();
				}
			};

			proc.stdout.on("data", (data: Buffer) => {
				stdoutBuffer += data.toString();
				const lines = stdoutBuffer.split("\n");
				stdoutBuffer = lines.pop() || "";
				for (const line of lines) processLine(line);
			});

			proc.stderr.on("data", (data: Buffer) => {
				currentResult.stderr += data.toString();
			});

			proc.on("close", (code) => {
				// Process any remaining buffered output
				if (stdoutBuffer.trim()) processLine(stdoutBuffer);
				resolve(code ?? 0);
			});

			proc.on("error", () => {
				resolve(1);
			});

			// --- Abort handling ---
			if (signal) {
				const killProc = () => {
					wasAborted = true;
					proc.kill("SIGTERM");
					setTimeout(() => {
						if (!proc.killed) proc.kill("SIGKILL");
					}, 5000);
				};
				if (signal.aborted) killProc();
				else signal.addEventListener("abort", killProc, { once: true });
			}
		});

		currentResult.exitCode = exitCode;

		if (wasAborted) {
			currentResult.stopReason = "aborted";
			currentResult.errorMessage = "Subagent was aborted by user";
		}
	} finally {
		// --- 5. Finalize trace & cleanup ---
		const stopReason = currentResult.stopReason || (currentResult.exitCode === 0 ? "end_turn" : "error");
		currentResult.traceSummary = traceLogger.finalize(
			currentResult.exitCode,
			stopReason,
			currentResult.finalOutput,
		);

		// Clean up temp prompt file
		if (tmpPromptPath) {
			try {
				fs.unlinkSync(tmpPromptPath);
			} catch {
				/* ignore */
			}
		}
	}

	return currentResult;
}
