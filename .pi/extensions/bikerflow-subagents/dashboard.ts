/**
 * Dashboard Module — Themed TUI Rendering for Pipeline Status
 *
 * Pure rendering functions that convert pipeline manifests and trace logs
 * into themed output. Phase 6's command handlers will use these inside
 * ctx.ui.custom() to display dashboard output.
 *
 * Uses lazy TUI imports — works both inside pi's runtime (with full TUI)
 * and in standalone test contexts (with lightweight fallbacks).
 *
 * Five public functions:
 *   renderPipelineStatus  → main pipeline view
 *   renderTraceLog        → per-turn trace breakdown (last 10 turns)
 *   renderManifest        → structured manifest dump
 *   renderSummary         → aggregate stats across pipelines
 *   findTraceLog          → locate most recent trace file for a persona
 */

import * as fs from "node:fs";
import * as path from "node:path";
import type { PipelineManifest, StageRecord, StageStatus } from "./pipeline.js";
import type { TraceEvent } from "./observability.js";

// ---------------------------------------------------------------------------
// Lazy TUI loading — resolved at call time inside pi's runtime
// ---------------------------------------------------------------------------

interface Renderable {
	render(width: number): string[];
	invalidate(): void;
}

interface ContainerLike extends Renderable {
	addChild(child: unknown): void;
	clear(): void;
}

let _Container: new () => ContainerLike;
let _Text: new (content: string, px: number, py: number) => Renderable;
let _Spacer: new (height: number) => Renderable;
let _tuiLoaded = false;

function loadTui(): void {
	if (_tuiLoaded) return;
	_tuiLoaded = true;
	try {
		const tui = require("@mariozechner/pi-tui");
		_Container = tui.Container;
		_Text = tui.Text;
		_Spacer = tui.Spacer;
	} catch {
		// Fallback for testing / non-TUI contexts
		_Container = class implements ContainerLike {
			private children: unknown[] = [];
			addChild(c: unknown) { this.children.push(c); }
			clear() { this.children = []; }
			render(w: number): string[] {
				const lines: string[] = [];
				for (const child of this.children) {
					if (child && typeof child === "object" && "render" in child) {
						lines.push(...(child as Renderable).render(w));
					}
				}
				return lines;
			}
			invalidate() {}
		};
		_Text = class implements Renderable {
			constructor(private content: string) {}
			render(_w: number): string[] { return [this.content]; }
			invalidate() {}
		};
		_Spacer = class implements Renderable {
			constructor(private height: number) {}
			render(_w: number): string[] { return Array(this.height).fill(""); }
			invalidate() {}
		};
	}
}

function makeContainer(): ContainerLike { loadTui(); return new _Container(); }
function makeText(content: string, px = 0, py = 0): Renderable { loadTui(); return new _Text(content, px, py); }
function makeSpacer(height = 1): Renderable { loadTui(); return new _Spacer(height); }

// ---------------------------------------------------------------------------
// Theme type (matches pi's theme.fg/bg API)
// ---------------------------------------------------------------------------

export interface DashboardTheme {
	fg: (color: string, text: string) => string;
	bg: (color: string, text: string) => string;
	bold: (text: string) => string;
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const MAX_TRACE_TURNS = 10;
const DEFAULT_SINCE_DAYS = 30;

// ---------------------------------------------------------------------------
// renderPipelineStatus
// ---------------------------------------------------------------------------

/**
 * Render the main pipeline status view.
 * Shows header + per-stage status line + totals.
 */
export function renderPipelineStatus(manifest: PipelineManifest, theme: DashboardTheme): ContainerLike {
	const c = makeContainer();

	// Header
	c.addChild(makeText(theme.fg("accent", theme.bold(`Pipeline: ${manifest.pipelineId}`)), 1, 0));
	c.addChild(makeText(theme.fg("text", `Task: ${manifest.taskId}`), 1, 0));
	c.addChild(makeText(theme.fg("text", `Workflow: ${manifest.workflow}`), 1, 0));

	// Status line
	const statusColor = statusColorOf(manifest.status);
	const runningIndex = manifest.stages.findIndex((s) => s.status === "running");
	const displayNum = runningIndex >= 0 ? runningIndex + 1 : manifest.stages.length;
	c.addChild(makeText(
		theme.fg(statusColor, `Status: ${manifest.status}`) +
		theme.fg("dim", ` (stage ${displayNum}/${manifest.stages.length})`),
		1, 0,
	));
	c.addChild(makeSpacer(1));

	// Stage lines
	for (const stage of manifest.stages) {
		c.addChild(makeText(renderStageLine(stage, theme), 1, 0));
		if (stage.hookWarning) {
			c.addChild(makeText(theme.fg("warning", `  ⚠ ${stage.hookWarning}`), 1, 0));
		}
	}

	// Totals from completed stages
	const completed = manifest.stages.filter((s) => s.status === "completed");
	if (completed.length > 0) {
		const totalDuration = completed.reduce((sum, s) => sum + (s.durationMs || 0), 0);
		const totalTurns = completed.reduce((sum, s) => sum + (s.turns || 0), 0);
		const totalCost = completed.reduce((sum, s) => sum + (s.cost || 0), 0);
		c.addChild(makeSpacer(1));
		c.addChild(makeText(
			theme.fg("dim",
				`Total so far: ${formatDuration(totalDuration)}  ${totalTurns} turns  $${totalCost.toFixed(4)}`,
			),
			1, 0,
		));
	}

	return c;
}

// ---------------------------------------------------------------------------
// renderTraceLog
// ---------------------------------------------------------------------------

/**
 * Render a trace log breakdown.
 * Shows header stats + per-turn tool calls and assistant text.
 * Limited to last MAX_TRACE_TURNS turns.
 */
export function renderTraceLog(
	traceEvents: TraceEvent[],
	persona: string,
	taskId: string,
	theme: DashboardTheme,
): ContainerLike {
	const c = makeContainer();

	// Extract aggregate stats from events
	const agentEnd = traceEvents.find((e) => e.event === "agent_end");
	const totalTurns = (agentEnd?.totalTurns as number) ?? 0;
	const totalDurationMs = (agentEnd?.totalDurationMs as number) ?? 0;
	const totalCost = (agentEnd?.totalCost as number) ?? 0;

	// Header
	c.addChild(makeText(theme.fg("accent", theme.bold(`Trace: ${persona}-${taskId}`)), 1, 0));

	const headerParts: string[] = [`Persona: ${persona}`, `Task: ${taskId}`];
	if (totalTurns) headerParts.push(`Turns: ${totalTurns}`);
	if (totalDurationMs) headerParts.push(`Duration: ${formatDuration(totalDurationMs)}`);
	if (totalCost) headerParts.push(`Cost: $${totalCost.toFixed(4)}`);
	c.addChild(makeText(theme.fg("muted", headerParts.join(" | ")), 1, 0));
	c.addChild(makeSpacer(1));

	// Group events by turn
	const turns = groupEventsByTurn(traceEvents);
	const turnsToShow = turns.slice(-MAX_TRACE_TURNS);
	const skipped = turns.length - turnsToShow.length;

	if (skipped > 0) {
		c.addChild(makeText(theme.fg("dim", `Showing last ${turnsToShow.length} of ${turns.length} turns`), 1, 0));
		c.addChild(makeSpacer(1));
	}

	for (const turn of turnsToShow) {
		c.addChild(makeText(theme.fg("toolTitle", theme.bold(`Turn ${turn.turnNumber}:`)), 1, 0));

		for (const tc of turn.toolCalls) {
			const durationStr = tc.durationMs !== undefined ? ` (${formatDuration(tc.durationMs)})` : "";
			const successIcon = tc.success ? theme.fg("success", "→") : theme.fg("error", "✗");
			c.addChild(makeText(
				`  ${successIcon} ${theme.fg("accent", tc.tool)} ${theme.fg("dim", tc.argsPreview)}${theme.fg("dim", durationStr)}`,
				1, 0,
			));
		}

		if (turn.assistantText) {
			const preview = truncateLines(turn.assistantText, 3);
			c.addChild(makeText(`  ${theme.fg("muted", "Assistant:")} ${theme.fg("dim", preview)}`, 1, 0));
		}

		c.addChild(makeSpacer(1));
	}

	if (turnsToShow.length === 0) {
		c.addChild(makeText(theme.fg("muted", "No turn data in trace."), 1, 0));
	}

	return c;
}

// ---------------------------------------------------------------------------
// renderManifest
// ---------------------------------------------------------------------------

/**
 * Render a pipeline manifest as a structured key-value view.
 */
export function renderManifest(manifest: PipelineManifest, theme: DashboardTheme): ContainerLike {
	const c = makeContainer();

	c.addChild(makeText(theme.fg("accent", theme.bold(`Pipeline Manifest: ${manifest.pipelineId}`)), 1, 0));
	c.addChild(makeSpacer(1));

	// Key-value header fields
	const headerFields: Array<[string, string]> = [
		["Pipeline ID", manifest.pipelineId],
		["Task ID", manifest.taskId],
		["Workflow", manifest.workflow],
		["Status", manifest.status],
		["Started", manifest.startedAt],
		["Completed", manifest.completedAt ?? "—"],
		["Current Stage", `${manifest.currentStageIndex} (${manifest.stages[manifest.currentStageIndex]?.persona ?? "—"})`],
	];

	for (const [label, value] of headerFields) {
		const color = label === "Status" ? statusColorOf(manifest.status) : "text";
		c.addChild(makeText(`  ${theme.fg("muted", label.padEnd(16))} ${theme.fg(color, value)}`, 1, 0));
	}

	// Stages
	c.addChild(makeSpacer(1));
	c.addChild(makeText(theme.fg("toolTitle", theme.bold(`Stages (${manifest.stages.length}):`)), 1, 0));

	for (let i = 0; i < manifest.stages.length; i++) {
		const stage = manifest.stages[i];
		c.addChild(makeSpacer(1));
		c.addChild(makeText(
			`  ${theme.fg("dim", `[${i}]`)} ${stageIcon(stage.status)} ${theme.fg("accent", stage.persona)}`,
			1, 0,
		));

		const stageFields: Array<[string, string | undefined]> = [
			["Status", stage.status],
			["Started", stage.startedAt],
			["Completed", stage.completedAt],
			["Duration", stage.durationMs !== undefined ? formatDuration(stage.durationMs) : undefined],
			["Turns", stage.turns?.toString()],
			["Cost", stage.cost !== undefined ? `$${stage.cost.toFixed(4)}` : undefined],
			["Trace Log", stage.traceLog],
			["Summary", stage.outputSummary],
			["Error", stage.errorMessage],
			["Hook Warning", stage.hookWarning],
		];

		for (const [label, value] of stageFields) {
			if (value === undefined) continue;
			const color = label === "Status" ? statusColorOf(stage.status) :
				label === "Error" ? "error" :
				label === "Hook Warning" ? "warning" : "dim";
			c.addChild(makeText(`      ${theme.fg("muted", label.padEnd(14))} ${theme.fg(color, value)}`, 1, 0));
		}

		if (stage.outputArtifacts && stage.outputArtifacts.length > 0) {
			c.addChild(makeText(`      ${theme.fg("muted", "Artifacts".padEnd(14))} ${theme.fg("dim", stage.outputArtifacts.join(", "))}`, 1, 0));
		}

		if (stage.personaData) {
			const pdLines = renderPersonaDataLines(stage.personaData as Record<string, unknown>, theme);
			for (const line of pdLines) {
				c.addChild(makeText(`      ${theme.fg("muted", "Persona Data".padEnd(14))} ${line}`, 1, 0));
			}
		}
	}

	return c;
}

// ---------------------------------------------------------------------------
// renderSummary
// ---------------------------------------------------------------------------

/**
 * Render aggregate stats across multiple pipelines.
 * Shows pipeline counts + per-persona averages + totals.
 *
 * @param manifests - Array of pipeline manifests
 * @param theme - Dashboard theme
 * @param since - Optional cutoff date. Defaults to 30 days ago.
 */
export function renderSummary(
	manifests: PipelineManifest[],
	theme: DashboardTheme,
	since?: Date,
): ContainerLike {
	const c = makeContainer();

	const cutoff = since ?? daysAgo(DEFAULT_SINCE_DAYS);
	const filtered = manifests.filter((m) => new Date(m.startedAt) >= cutoff);

	const sinceLabel = since
		? `since ${since.toISOString().slice(0, 10)}`
		: `last ${DEFAULT_SINCE_DAYS} days`;
	c.addChild(makeText(theme.fg("accent", theme.bold(`BikerFlow Agent Summary (${sinceLabel})`)), 1, 0));
	c.addChild(makeText(theme.fg("dim", "─".repeat(40)), 1, 0));

	if (filtered.length === 0) {
		c.addChild(makeText(theme.fg("muted", "No pipelines found."), 1, 0));
		return c;
	}

	// Pipeline counts
	const byStatus = new Map<string, number>();
	for (const m of filtered) {
		byStatus.set(m.status, (byStatus.get(m.status) || 0) + 1);
	}
	const statusParts: string[] = [];
	for (const [status, count] of byStatus) {
		statusParts.push(`${count} ${status}`);
	}
	c.addChild(makeText(theme.fg("text", `Pipelines: ${statusParts.join(", ")}`), 1, 0));
	c.addChild(makeSpacer(1));

	// Per-persona stats header
	c.addChild(makeText(theme.fg("toolTitle", theme.bold("Per Persona:")), 1, 0));

	// Collect completed stages across all pipelines
	const personaStats = new Map<string, {
		runs: number;
		totalDurationMs: number;
		totalTurns: number;
		totalCost: number;
	}>();

	for (const m of filtered) {
		for (const stage of m.stages) {
			if (stage.status !== "completed") continue;
			const existing = personaStats.get(stage.persona) ?? {
				runs: 0, totalDurationMs: 0, totalTurns: 0, totalCost: 0,
			};
			existing.runs++;
			existing.totalDurationMs += stage.durationMs ?? 0;
			existing.totalTurns += stage.turns ?? 0;
			existing.totalCost += stage.cost ?? 0;
			personaStats.set(stage.persona, existing);
		}
	}

	// Display in fixed persona order, then any extras
	const personaOrder = ["planner", "tester", "developer", "validator", "tracker", "sandbox"];
	const sortedPersonas = [
		...personaOrder.filter((p) => personaStats.has(p)),
		...([...personaStats.keys()].filter((p) => !personaOrder.includes(p))),
	];

	for (const persona of sortedPersonas) {
		const stats = personaStats.get(persona)!;
		const avgDuration = stats.runs > 0 ? Math.round(stats.totalDurationMs / stats.runs) : 0;
		const avgTurns = stats.runs > 0 ? (stats.totalTurns / stats.runs).toFixed(1) : "0.0";

		c.addChild(makeText(
			`  ${theme.fg("accent", persona.padEnd(12))}` +
			theme.fg("text", `${stats.runs} runs`.padEnd(8)) +
			theme.fg("dim", `avg ${formatDuration(avgDuration)}`.padEnd(12)) +
			theme.fg("dim", `avg ${avgTurns} turns`.padEnd(16)) +
			theme.fg("dim", `total $${stats.totalCost.toFixed(4)}`),
			1, 0,
		));
	}

	// Grand total
	c.addChild(makeSpacer(1));
	const totalRuns = [...personaStats.values()].reduce((sum, s) => sum + s.runs, 0);
	const totalCost = [...personaStats.values()].reduce((sum, s) => sum + s.totalCost, 0);
	c.addChild(makeText(theme.fg("dim", `Total: $${totalCost.toFixed(4)} across ${totalRuns} subagent runs`), 1, 0));

	return c;
}

// ---------------------------------------------------------------------------
// findTraceLog
// ---------------------------------------------------------------------------

/**
 * Find the most recent trace log file for a given persona.
 *
 * @param persona - Persona name (e.g. "planner")
 * @param logsDir - Directory containing trace logs
 * @param taskId  - Optional task ID to filter by
 * @returns Full path to the trace file, or null if none found
 */
export function findTraceLog(persona: string, logsDir: string, taskId?: string): string | null {
	try {
		const entries = fs.readdirSync(logsDir);
		const matches = entries
			.filter((e) => e.endsWith(".jsonl"))
			.filter((e) => e.includes(`-${persona}-`))
			.filter((e) => !taskId || e.includes(`-${taskId.replace(/[^\w.-]/g, "_")}`))
			.sort()
			.reverse(); // newest first (date prefix in filename)

		if (matches.length === 0) return null;
		return path.join(logsDir, matches[0]);
	} catch {
		return null;
	}
}

/**
 * Parse a trace log JSONL file into TraceEvent array.
 */
export function readTraceLog(filePath: string): TraceEvent[] {
	try {
		const content = fs.readFileSync(filePath, "utf-8").trim();
		if (!content) return [];
		return content
			.split("\n")
			.filter(Boolean)
			.map((line) => {
				try { return JSON.parse(line) as TraceEvent; }
				catch { return null; }
			})
			.filter((e): e is TraceEvent => e !== null);
	} catch {
		return [];
	}
}

// ---------------------------------------------------------------------------
// Private: stage line rendering
// ---------------------------------------------------------------------------

function renderStageLine(stage: StageRecord, theme: DashboardTheme): string {
	const icon = stageIcon(stage.status);
	const color = statusColorOf(stage.status);

	const parts: string[] = [];
	parts.push(`${icon} ${theme.fg("accent", stage.persona.padEnd(12))}`);

	if (stage.durationMs !== undefined) {
		parts.push(theme.fg("dim", formatDuration(stage.durationMs).padEnd(6)));
	} else {
		parts.push("      ");
	}

	if (stage.turns !== undefined) {
		parts.push(theme.fg("dim", `${stage.turns}t`.padEnd(5)));
	} else {
		parts.push("     ");
	}

	if (stage.cost !== undefined) {
		parts.push(theme.fg("dim", `$${stage.cost.toFixed(4)}`.padEnd(9)));
	} else {
		parts.push("         ");
	}

	if (stage.status === "running") {
		parts.push(theme.fg(color, "... running ..."));
	} else if (stage.status === "pending") {
		parts.push(theme.fg("dim", "pending"));
	} else if (stage.outputSummary) {
		parts.push(theme.fg("dim", `→ ${truncate(stage.outputSummary, 60)}`));
	}

	return parts.join("");
}

function stageIcon(status: StageStatus): string {
	const icons: Record<StageStatus, string> = {
		pending: "·",
		running: "⏳",
		completed: "✓",
		failed: "✗",
		aborted: "⊘",
	};
	return icons[status];
}

function statusColorOf(status: string): string {
	switch (status) {
		case "completed": return "success";
		case "running": return "warning";
		case "failed": return "error";
		case "aborted": return "error";
		case "pending": return "dim";
		default: return "text";
	}
}

// ---------------------------------------------------------------------------
// Private: trace log grouping
// ---------------------------------------------------------------------------

interface TurnData {
	turnNumber: number;
	toolCalls: Array<{
		tool: string;
		argsPreview: string;
		durationMs?: number;
		success: boolean;
	}>;
	assistantText?: string;
}

function groupEventsByTurn(events: TraceEvent[]): TurnData[] {
	const turns: TurnData[] = [];
	let currentTurn: TurnData | null = null;

	for (const event of events) {
		switch (event.event) {
			case "turn_start": {
				currentTurn = { turnNumber: (event.turn as number) ?? 0, toolCalls: [] };
				break;
			}
			case "turn_end": {
				if (currentTurn) turns.push(currentTurn);
				currentTurn = null;
				break;
			}
			case "tool_execution_start": {
				if (currentTurn) {
					currentTurn.toolCalls.push({
						tool: (event.tool as string) ?? "?",
						argsPreview: formatToolArgsPreview(event),
						success: true,
					});
				}
				break;
			}
			case "tool_execution_end": {
				if (currentTurn && currentTurn.toolCalls.length > 0) {
					const last = currentTurn.toolCalls[currentTurn.toolCalls.length - 1]!;
					last.durationMs = event.durationMs as number | undefined;
					last.success = event.success !== false;
				}
				break;
			}
			case "message_end": {
				if (currentTurn && (event.role as string) === "assistant") {
					const msg = event.message as Record<string, unknown> | undefined;
					if (msg) {
						const content = msg.content as Array<Record<string, unknown>> | undefined;
						if (Array.isArray(content)) {
							for (const part of content) {
								if (part.type === "text" && typeof part.text === "string") {
									currentTurn.assistantText = part.text;
								}
							}
						}
					}
				}
				break;
			}
		}
	}

	// Dangling turn (no turn_end)
	if (currentTurn) turns.push(currentTurn);

	return turns;
}

function formatToolArgsPreview(event: TraceEvent): string {
	const args = event.args as Record<string, unknown> | undefined;
	if (!args) return "";
	if (args.path) return String(args.path);
	if (args.command) return truncate(String(args.command), 60);
	if (args.pattern) return String(args.pattern);
	return truncate(JSON.stringify(args), 60);
}

// ---------------------------------------------------------------------------
// Private: persona data rendering
// ---------------------------------------------------------------------------

function renderPersonaDataLines(
	pd: Record<string, unknown>,
	theme: DashboardTheme,
): string[] {
	const lines: string[] = [];
	const data = pd.data as Record<string, unknown> | undefined;
	if (!data) return lines;

	switch (pd.type) {
		case "planner": {
			if (data.planFilePath) lines.push(theme.fg("dim", `plan: ${data.planFilePath}`));
			if (Array.isArray(data.businessRules)) lines.push(theme.fg("dim", `rules: ${data.businessRules.join(", ")}`));
			if (data.complexity) lines.push(theme.fg("dim", `complexity: ${data.complexity}`));
			break;
		}
		case "tester": {
			if (Array.isArray(data.testFiles)) lines.push(theme.fg("dim", `test files: ${data.testFiles.length}`));
			if (data.redCount !== undefined) lines.push(theme.fg("dim", `RED: ${data.redCount}`));
			break;
		}
		case "developer": {
			if (Array.isArray(data.filesCreated)) lines.push(theme.fg("dim", `created: ${data.filesCreated.length} files`));
			if (data.testsPassing !== undefined) {
				const icon = data.testsPassing ? "✓" : "✗";
				lines.push(theme.fg(data.testsPassing ? "success" : "error", `tests: ${icon}`));
			}
			break;
		}
		case "validator": {
			if (data.verdict) {
				const color = data.verdict === "PASS" ? "success" : data.verdict === "FAIL" ? "error" : "warning";
				lines.push(theme.fg(color, `verdict: ${data.verdict}`));
			}
			if (Array.isArray(data.findings)) lines.push(theme.fg("dim", `findings: ${data.findings.length}`));
			break;
		}
		case "tracker": {
			if (data.progressUpdated !== undefined) {
				lines.push(theme.fg("dim", `progress.md: ${data.progressUpdated ? "updated" : "not updated"}`));
			}
			break;
		}
		default:
			lines.push(theme.fg("dim", `type: ${pd.type}`));
	}

	return lines;
}

// ---------------------------------------------------------------------------
// Private: formatting helpers
// ---------------------------------------------------------------------------

function formatDuration(ms: number): string {
	if (ms < 1000) return `${ms}ms`;
	const seconds = Math.floor(ms / 1000);
	if (seconds < 60) return `${seconds}s`;
	const minutes = Math.floor(seconds / 60);
	const remainingSeconds = seconds % 60;
	return `${minutes}m${remainingSeconds.toString().padStart(2, "0")}s`;
}

function truncate(str: string, maxLen: number): string {
	if (str.length <= maxLen) return str;
	return str.slice(0, maxLen - 3) + "...";
}

function truncateLines(text: string, maxLines: number): string {
	const lines = text.split("\n");
	if (lines.length <= maxLines) return text;
	return lines.slice(0, maxLines).join("\n") + ` ... (+${lines.length - maxLines} more lines)`;
}

function daysAgo(n: number): Date {
	const d = new Date();
	d.setDate(d.getDate() - n);
	return d;
}
