/**
 * BikerFlow Subagents Extension
 *
 * Spawns BikerFlow personas (planner, tester, developer, validator, tracker, sandbox)
 * as isolated `pi --mode json` subprocesses with full observability.
 *
 * Phase 6: Full extension wiring.
 * Registers: subagent tool, /agents command, /tdd, /plan, /implement commands.
 */

import * as path from "node:path";
import { Type } from "typebox";
import type { ExtensionAPI } from "@mariozechner/pi-coding-agent";

// Observability (Phase 2)
export { LogSanitizer, TraceLogger } from "./observability.js";
export type { TraceEvent, TraceSummary } from "./observability.js";

// Agent Discovery (Phase 3)
export { discoverAgents, findAgent } from "./agents.js";
export type { AgentConfig } from "./agents.js";

// Subagent Spawning (Phase 3)
export { runSubagent } from "./subagent.js";
export type { SubagentConfig, SubagentResult, UsageStats } from "./subagent.js";

// Pipeline Manager (Phase 4)
export { PipelineManager } from "./pipeline.js";
export type {
	PipelineManifest,
	PipelineStatus,
	StageRecord,
	StageStatus,
	AdvanceResult,
} from "./pipeline.js";

// Stop Hooks (Phase 4)
export { getStopHook } from "./hooks.js";
export type {
	StageSummary,
	PersonaData,
	PlannerData,
	TesterData,
	DeveloperData,
	ValidatorData,
	TrackerData,
	SandboxData,
} from "./hooks.js";

// Dashboard (Phase 5)
export {
	renderPipelineStatus,
	renderTraceLog,
	renderManifest,
	renderSummary,
	findTraceLog,
	readTraceLog,
} from "./dashboard.js";
export type { DashboardTheme } from "./dashboard.js";

// Workflows (Phase 6)
export { discoverWorkflows, findWorkflow } from "./workflows.js";
export type { WorkflowDefinition } from "./workflows.js";

// Orchestrator (Phase 6)
export { executeChain, executeWorkflow, buildStageTask } from "./orchestrator.js";
export type { OrchestratorConfig, OrchestratorResult, ChainStage } from "./orchestrator.js";

// ---------------------------------------------------------------------------
// Re-imports used in wiring (full modules needed at runtime)
// ---------------------------------------------------------------------------

import { PipelineManager } from "./pipeline.js";
import type { PipelineManifest } from "./pipeline.js";
import type { TraceEvent } from "./observability.js";
import { runSubagent } from "./subagent.js";
import { executeChain, executeWorkflow } from "./orchestrator.js";
import {
	renderPipelineStatus,
	renderTraceLog,
	renderManifest,
	renderSummary,
	findTraceLog,
	readTraceLog,
} from "./dashboard.js";

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const AGENTS_DIR = "docs/agents";
const LOGS_SUBDIR = "logs";
const PIPELINES_SUBDIR = "pipelines";

// ---------------------------------------------------------------------------
// Tool parameter schema
// ---------------------------------------------------------------------------

const ChainItemSchema = Type.Object({
	persona: Type.String({ description: "Persona name to invoke" }),
	task: Type.String({ description: "Task with optional {previous} placeholder" }),
});

const SubagentParams = Type.Object({
	persona: Type.Optional(Type.String({ description: "Persona name (single mode)" })),
	task: Type.Optional(Type.String({ description: "Task description (single mode)" })),
	chain: Type.Optional(Type.Array(ChainItemSchema, { description: "Ordered stages for sequential execution" })),
	workflow: Type.Optional(Type.String({ description: "Workflow name (e.g. 'full-tdd', 'plan-only')" })),
	taskId: Type.Optional(Type.String({ description: "Short task identifier (e.g. 'US-01')" })),
	model: Type.Optional(Type.String({ description: "Model override" })),
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function resolveDirs(cwd: string) {
	return {
		logsDir: path.join(cwd, AGENTS_DIR, LOGS_SUBDIR),
		pipelinesDir: path.join(cwd, AGENTS_DIR, PIPELINES_SUBDIR),
	};
}

function parseTaskId(args: string): string {
	const match = args.match(/\b(US-\d+|BR-\d+|phase-\d+)\b/i);
	if (match) return match[1].toUpperCase();
	return args.split(/\s+/)[0] || "task";
}

function formatDuration(ms: number): string {
	if (ms < 1000) return `${ms}ms`;
	const seconds = Math.floor(ms / 1000);
	if (seconds < 60) return `${seconds}s`;
	const minutes = Math.floor(seconds / 60);
	const remainingSeconds = seconds % 60;
	return `${minutes}m${remainingSeconds.toString().padStart(2, "0")}s`;
}

function makeText(text: string) {
	return { type: "text" as const, text };
}

// ---------------------------------------------------------------------------
// Theme adapter: maps pi theme to DashboardTheme interface
// ---------------------------------------------------------------------------

interface PiTheme {
	fg: (color: string, text: string) => string;
	bg: (color: string, text: string) => string;
	bold: (text: string) => string;
}

function makeThemeAdapter(theme: PiTheme) {
	return {
		fg: theme.fg.bind(theme),
		bg: theme.bg.bind(theme),
		bold: theme.bold.bind(theme),
	};
}

// ---------------------------------------------------------------------------
// Plain-text fallback renderers
// ---------------------------------------------------------------------------

function renderPipelineStatusText(manifest: PipelineManifest): string {
	const lines: string[] = [];
	lines.push(`Pipeline: ${manifest.pipelineId}`);
	lines.push(`Task: ${manifest.taskId}`);
	lines.push(`Workflow: ${manifest.workflow}`);
	const runningIndex = manifest.stages.findIndex((s) => s.status === "running");
	const displayNum = runningIndex >= 0 ? runningIndex + 1 : manifest.stages.length;
	lines.push(`Status: ${manifest.status} (stage ${displayNum}/${manifest.stages.length})`);
	lines.push("");

	for (const stage of manifest.stages) {
		const icon: Record<string, string> = {
			pending: "·", running: "⏳", completed: "✓", failed: "✗", aborted: "⊘",
		}[stage.status] || "?";
		const duration = stage.durationMs !== undefined ? ` ${formatDuration(stage.durationMs)}` : "";
		const turns = stage.turns !== undefined ? ` ${stage.turns}t` : "";
		const cost = stage.cost !== undefined ? ` $${stage.cost.toFixed(4)}` : "";
		const status = stage.status === "running" ? "... running ..." :
			stage.status === "pending" ? "pending" :
			stage.outputSummary ? `→ ${stage.outputSummary}` : stage.status;
		lines.push(`${icon} ${stage.persona.padEnd(12)}${duration}${turns}${cost} ${status}`);
	}

	const completed = manifest.stages.filter((s) => s.status === "completed");
	if (completed.length > 0) {
		const totalDuration = completed.reduce((sum, s) => sum + (s.durationMs || 0), 0);
		const totalTurns = completed.reduce((sum, s) => sum + (s.turns || 0), 0);
		const totalCost = completed.reduce((sum, s) => sum + (s.cost || 0), 0);
		lines.push("");
		lines.push(`Total so far: ${formatDuration(totalDuration)}  ${totalTurns} turns  $${totalCost.toFixed(4)}`);
	}

	return lines.join("\n");
}

function renderSummaryText(manifests: PipelineManifest[]): string {
	if (manifests.length === 0) return "No pipelines found.";

	const since = new Date();
	since.setDate(since.getDate() - 30);
	const filtered = manifests.filter((m) => new Date(m.startedAt) >= since);

	const lines: string[] = [];
	lines.push("BikerFlow Agent Summary (last 30 days)");
	lines.push("─".repeat(40));

	if (filtered.length === 0) {
		lines.push("No pipelines found in the last 30 days.");
		return lines.join("\n");
	}

	const byStatus = new Map<string, number>();
	for (const m of filtered) {
		byStatus.set(m.status, (byStatus.get(m.status) || 0) + 1);
	}
	lines.push(`Pipelines: ${[...byStatus.entries()].map(([s, c]) => `${c} ${s}`).join(", ")}`);
	lines.push("");

	const personaStats = new Map<string, { runs: number; totalDurationMs: number; totalTurns: number; totalCost: number }>();
	for (const m of filtered) {
		for (const stage of m.stages) {
			if (stage.status !== "completed") continue;
			const existing = personaStats.get(stage.persona) ?? { runs: 0, totalDurationMs: 0, totalTurns: 0, totalCost: 0 };
			existing.runs++;
			existing.totalDurationMs += stage.durationMs ?? 0;
			existing.totalTurns += stage.turns ?? 0;
			existing.totalCost += stage.cost ?? 0;
			personaStats.set(stage.persona, existing);
		}
	}

	lines.push("Per Persona:");
	const personaOrder = ["planner", "tester", "developer", "validator", "tracker", "sandbox"];
	for (const persona of [...personaOrder.filter((p) => personaStats.has(p)), ...[...personaStats.keys()].filter((p) => !personaOrder.includes(p))]) {
		const stats = personaStats.get(persona)!;
		const avgDuration = stats.runs > 0 ? Math.round(stats.totalDurationMs / stats.runs) : 0;
		const avgTurns = stats.runs > 0 ? (stats.totalTurns / stats.runs).toFixed(1) : "0.0";
		lines.push(`  ${persona.padEnd(12)} ${stats.runs} runs  avg ${formatDuration(avgDuration)}  avg ${avgTurns} turns  total $${stats.totalCost.toFixed(4)}`);
	}

	const totalRuns = [...personaStats.values()].reduce((sum, s) => sum + s.runs, 0);
	const totalCost = [...personaStats.values()].reduce((sum, s) => sum + s.totalCost, 0);
	lines.push("");
	lines.push(`Total: $${totalCost.toFixed(4)} across ${totalRuns} subagent runs`);
	return lines.join("\n");
}

function renderManifestText(manifest: PipelineManifest): string {
	const lines: string[] = [];
	lines.push(`Pipeline Manifest: ${manifest.pipelineId}`);
	lines.push(`  Task ID:     ${manifest.taskId}`);
	lines.push(`  Workflow:    ${manifest.workflow}`);
	lines.push(`  Status:      ${manifest.status}`);
	lines.push(`  Started:     ${manifest.startedAt}`);
	lines.push(`  Completed:   ${manifest.completedAt ?? "—"}`);
	lines.push("");

	for (let i = 0; i < manifest.stages.length; i++) {
		const stage = manifest.stages[i];
		const icon: Record<string, string> = { pending: "·", running: "⏳", completed: "✓", failed: "✗", aborted: "⊘" }[stage.status] || "?";
		lines.push(`  [${i}] ${icon} ${stage.persona}`);
		if (stage.status !== "pending") lines.push(`      Status:   ${stage.status}`);
		if (stage.durationMs !== undefined) lines.push(`      Duration: ${formatDuration(stage.durationMs)}`);
		if (stage.turns !== undefined) lines.push(`      Turns:    ${stage.turns}`);
		if (stage.cost !== undefined) lines.push(`      Cost:     $${stage.cost.toFixed(4)}`);
		if (stage.outputSummary) lines.push(`      Summary:  ${stage.outputSummary}`);
		if (stage.traceLog) lines.push(`      Trace:    ${stage.traceLog}`);
		if (stage.errorMessage) lines.push(`      Error:    ${stage.errorMessage}`);
		if (stage.outputArtifacts && stage.outputArtifacts.length > 0) {
			lines.push(`      Files:    ${stage.outputArtifacts.join(", ")}`);
		}
	}

	return lines.join("\n");
}

function renderTraceLogText(events: TraceEvent[], persona: string, taskId: string): string {
	const lines: string[] = [];
	const agentEnd = events.find((e) => e.event === "agent_end");
	const totalTurns = (agentEnd?.totalTurns as number) ?? 0;
	const totalDurationMs = (agentEnd?.totalDurationMs as number) ?? 0;
	const totalCost = (agentEnd?.totalCost as number) ?? 0;

	lines.push(`Trace: ${persona}-${taskId}`);
	const parts: string[] = [`Persona: ${persona}`, `Task: ${taskId}`];
	if (totalTurns) parts.push(`Turns: ${totalTurns}`);
	if (totalDurationMs) parts.push(`Duration: ${formatDuration(totalDurationMs)}`);
	if (totalCost) parts.push(`Cost: $${totalCost.toFixed(4)}`);
	lines.push(parts.join(" | "));
	lines.push("");

	let turnNum = 0;
	for (const event of events) {
		if (event.event === "turn_start") {
			turnNum = (event.turn as number) || turnNum + 1;
			lines.push(`Turn ${turnNum}:`);
		}
		if (event.event === "tool_execution_start") {
			const tool = event.tool as string;
			const args = event.args as Record<string, unknown> | undefined;
			const preview = args?.path ? String(args.path) : args?.command ? String(args.command).slice(0, 60) : "";
			lines.push(`  → ${tool} ${preview}`);
		}
		if (event.event === "tool_execution_end") {
			const tool = event.tool as string;
			const success = event.success !== false;
			const duration = event.durationMs ? ` (${formatDuration(event.durationMs as number)})` : "";
			lines.push(`  ${success ? "✓" : "✗"} ${tool}${duration}`);
		}
	}

	return lines.join("\n");
}

// ---------------------------------------------------------------------------
// Shared: show dashboard component with TUI fallback
// ---------------------------------------------------------------------------

async function showDashboard(
	ctx: { hasUI: boolean; ui: { custom: Function; notify: Function } },
	buildContainer: (theme: PiTheme) => import("./dashboard.js").ContainerLike,
	fallbackText: string,
): Promise<void> {
	if (ctx.hasUI) {
		try {
			await ctx.ui.custom<void>((_tui: unknown, theme: PiTheme, _keybindings: unknown, done: () => void) => {
				const container = buildContainer(theme);
				const originalOnKey = container.handleInput?.bind(container);
				container.handleInput = (data: string) => {
					if (data === "escape" || data === "q" || data === "return") {
						done();
						return true;
					}
					originalOnKey?.(data);
					return false;
				};
				return container;
			});
			return;
		} catch {
			// Fall through to plain text
		}
	}
	ctx.ui.notify(fallbackText, "info");
}

// ---------------------------------------------------------------------------
// Extension entry point
// ---------------------------------------------------------------------------

export default function (pi: ExtensionAPI) {
	// -----------------------------------------------------------------------
	// 1. subagent tool — single, chain, and workflow modes
	// -----------------------------------------------------------------------

	pi.registerTool({
		name: "subagent",
		label: "Subagent",
		description: [
			"Delegate tasks to specialized BikerFlow subagents with isolated contexts and full observability.",
			"Modes:",
			"  single — {persona, task} for one agent",
			"  chain — {chain: [{persona, task}...]} for sequential execution with {previous} placeholder",
			"  workflow — {workflow, task} to run a named pipeline (e.g. 'full-tdd', 'plan-only')",
		].join("\n"),
		parameters: SubagentParams,

		async execute(_toolCallId, params, signal, onUpdate, ctx) {
			const { logsDir, pipelinesDir } = resolveDirs(ctx.cwd);
			const taskId = params.taskId || "task";

			// --- Workflow mode ---
			if (params.workflow) {
				const userTask = params.task || params.workflow;
				const config = {
					cwd: ctx.cwd,
					logsDir,
					pipelinesDir,
					signal,
					onUpdate: onUpdate
						? (status: string) => { onUpdate({ content: [makeText(status)] }); }
						: undefined,
				};

				const result = await executeWorkflow(config, params.workflow, taskId, userTask);
				const summaryLines = formatOrchestratorResult(result);

				return {
					content: [makeText(summaryLines.join("\n"))],
					details: result,
					isError: !result.success,
				};
			}

			// --- Chain mode ---
			if (params.chain && params.chain.length > 0) {
				const config = {
					cwd: ctx.cwd,
					logsDir,
					pipelinesDir,
					signal,
					onUpdate: onUpdate
						? (status: string) => { onUpdate({ content: [makeText(status)] }); }
						: undefined,
				};

				const stages = params.chain.map((item) => ({
					persona: item.persona,
					task: item.task,
				}));

				const result = await executeChain(config, taskId, "custom-chain", stages);
				const summaryLines = formatOrchestratorResult(result);

				return {
					content: [makeText(summaryLines.join("\n"))],
					details: result,
					isError: !result.success,
				};
			}

			// --- Single mode ---
			if (params.persona && params.task) {
				const result = await runSubagent({
					persona: params.persona,
					task: params.task,
					taskId,
					cwd: ctx.cwd,
					logsDir,
					model: params.model,
					signal,
					onUpdate,
				});

				const isError = result.exitCode !== 0 || result.stopReason === "error";

				return {
					content: [makeText(result.finalOutput || result.stderr || "(no output)")],
					details: result,
					isError,
				};
			}

			// --- No valid mode ---
			return {
				content: [makeText(
					"Provide one of: {persona, task} for single mode, {chain: [...]} for chain mode, or {workflow, task} for workflow mode.",
				)],
				details: {},
			};
		},
	});

	// -----------------------------------------------------------------------
	// 2. /agents command — pipeline dashboard
	// -----------------------------------------------------------------------

	pi.registerCommand("agents", {
		description: "BikerFlow agent dashboard — pipeline status, trace logs, and aggregate stats",
		handler: async (args, ctx) => {
			const { logsDir, pipelinesDir } = resolveDirs(ctx.cwd);
			const trimmed = (args || "").trim();

			// --- /agents summary ---
			if (trimmed === "summary") {
				const manifests = PipelineManager.list(pipelinesDir);
				await showDashboard(
					ctx,
					(theme) => renderSummary(manifests, makeThemeAdapter(theme)),
					renderSummaryText(manifests),
				);
				return;
			}

			// --- /agents logs <persona> [taskId] ---
			if (trimmed.startsWith("logs ")) {
				const parts = trimmed.slice(5).trim().split(/\s+/);
				const persona = parts[0];
				const taskId = parts[1];

				if (!persona) {
					ctx.ui.notify("Usage: /agents logs <persona> [taskId]", "info");
					return;
				}

				const tracePath = findTraceLog(persona, logsDir, taskId);
				if (!tracePath) {
					ctx.ui.notify(`No trace logs found for persona "${persona}".`, "info");
					return;
				}

				const events = readTraceLog(tracePath);
				await showDashboard(
					ctx,
					(theme) => renderTraceLog(events, persona, taskId || "unknown", makeThemeAdapter(theme)),
					renderTraceLogText(events, persona, taskId || "unknown"),
				);
				return;
			}

			// --- /agents log <pipeline-id> ---
			if (trimmed.startsWith("log ")) {
				const pipelineId = trimmed.slice(4).trim();
				if (!pipelineId) {
					ctx.ui.notify("Usage: /agents log <pipeline-id>", "info");
					return;
				}

				const pm = PipelineManager.read(pipelineId, pipelinesDir);
				if (!pm) {
					ctx.ui.notify(`Pipeline "${pipelineId}" not found.`, "info");
					return;
				}

				const manifest = pm.getManifest();
				await showDashboard(
					ctx,
					(theme) => renderManifest(manifest, makeThemeAdapter(theme)),
					renderManifestText(manifest),
				);
				return;
			}

			// --- /agents run <workflow> <task> ---
			if (trimmed.startsWith("run ")) {
				const runArgs = trimmed.slice(4).trim();
				const parts = runArgs.split(/\s+/);
				const workflowName = parts[0];
				const taskInput = parts.slice(1).join(" ");

				if (!workflowName || !taskInput) {
					ctx.ui.notify("Usage: /agents run <workflow-name> <task-id> [description]\nAvailable workflows: full-tdd, plan-only, implement-only", "info");
					return;
				}

				const { logsDir, pipelinesDir } = resolveDirs(ctx.cwd);
				const taskId = parseTaskId(taskInput);

				ctx.ui.notify(`Starting ${workflowName} pipeline for ${taskId}...`, "info");

				const result = await executeWorkflow(
					{ cwd: ctx.cwd, logsDir, pipelinesDir },
					workflowName,
					taskId,
					taskInput,
				);

				if (result.success) {
					ctx.ui.notify(
						`✓ ${workflowName} pipeline completed for ${taskId} — ${formatDuration(result.totalDurationMs)} — $${result.totalCost.toFixed(4)}`,
						"success",
					);
				} else {
					ctx.ui.notify(
						`✗ ${workflowName} pipeline failed for ${taskId}: ${result.errorMessage}`,
						"error",
					);
				}
				return;
			}

			// --- /agents (no args) — show most recent pipeline ---
			const manifests = PipelineManager.list(pipelinesDir);
			if (manifests.length === 0) {
				ctx.ui.notify(
					"No pipelines found. Run `/tdd <task>` or `/plan <task>` to start one.",
					"info",
				);
				return;
			}

			const latest = manifests[0];
			await showDashboard(
				ctx,
				(theme) => renderPipelineStatus(latest, makeThemeAdapter(theme)),
				renderPipelineStatusText(latest),
			);
		},
	});

	// -----------------------------------------------------------------------
	// 3. /tdd command — full TDD workflow
	// -----------------------------------------------------------------------

	pi.registerCommand("tdd", {
		description: "Run the full TDD pipeline: plan → test RED → develop → validate → track",
		handler: async (args, ctx) => {
			const taskInput = (args || "").trim();
			if (!taskInput) {
				ctx.ui.notify("Usage: /tdd <task-id> [description]", "info");
				return;
			}

			const { logsDir, pipelinesDir } = resolveDirs(ctx.cwd);
			const taskId = parseTaskId(taskInput);

			ctx.ui.notify(`Starting full TDD pipeline for ${taskId}...`, "info");

			const result = await executeWorkflow(
				{ cwd: ctx.cwd, logsDir, pipelinesDir },
				"full-tdd",
				taskId,
				taskInput,
			);

			if (result.success) {
				ctx.ui.notify(
					`✓ TDD pipeline completed for ${taskId} — ${formatDuration(result.totalDurationMs)} — $${result.totalCost.toFixed(4)}`,
					"success",
				);
			} else {
				ctx.ui.notify(
					`✗ TDD pipeline failed for ${taskId}: ${result.errorMessage}`,
					"error",
				);
			}
		},
	});

	// -----------------------------------------------------------------------
	// 4. /agents run <workflow> <task> — run any workflow pipeline
	// -----------------------------------------------------------------------
	// Handled inside the /agents command below ("run" subcommand).
	// This avoids shadowing the existing /plan and /implement prompt templates
	// that are used for interactive (in-context) agent invocation.
}

// ---------------------------------------------------------------------------
// Result formatting helper
// ---------------------------------------------------------------------------

function formatOrchestratorResult(result: import("./orchestrator.js").OrchestratorResult): string[] {
	const summaryLines: string[] = [];
	summaryLines.push(`Pipeline: ${result.pipelineId}`);
	summaryLines.push(`Status: ${result.success ? "✓ completed" : `✗ ${result.errorMessage || "failed"}`}`);
	summaryLines.push(`Duration: ${formatDuration(result.totalDurationMs)}`);
	summaryLines.push(`Cost: $${result.totalCost.toFixed(4)}`);
	summaryLines.push("");

	for (const stage of result.stages) {
		const icon = stage.status === "completed" ? "✓" : stage.status === "running" ? "⏳" : "✗";
		const duration = stage.durationMs ? ` ${formatDuration(stage.durationMs)}` : "";
		const turns = stage.turns !== undefined ? ` ${stage.turns}t` : "";
		const cost = stage.cost !== undefined ? ` $${stage.cost.toFixed(4)}` : "";
		const summary = stage.outputSummary ? ` → ${stage.outputSummary}` : stage.status;
		summaryLines.push(`${icon} ${stage.persona.padEnd(12)}${duration}${turns}${cost} ${summary}`);
	}

	return summaryLines;
}
