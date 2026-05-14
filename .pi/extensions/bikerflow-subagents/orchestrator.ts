/**
 * Pipeline Orchestrator — chain execution engine
 *
 * Executes a multi-stage subagent pipeline, wiring together:
 *   - runSubagent → TraceLogger (observability)
 *   - getStopHook → structured stage summary
 *   - PipelineManager → manifest persistence + chain advancement
 *   - {previous} placeholder substitution between stages
 *
 * Used by both the `subagent` tool (workflow mode) and the
 * `/tdd`, `/plan`, `/implement` commands.
 */

import * as path from "node:path";
import { getStopHook } from "./hooks.js";
import type { StageSummary } from "./hooks.js";
import { PipelineManager } from "./pipeline.js";
import { runSubagent } from "./subagent.js";
import type { SubagentResult } from "./subagent.js";
import type { WorkflowDefinition } from "./workflows.js";
import { findWorkflow } from "./workflows.js";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface OrchestratorConfig {
	/** Project root / working directory */
	cwd: string;
	/** Directory for trace log files */
	logsDir: string;
	/** Directory for pipeline manifest files */
	pipelinesDir: string;
	/** Abort signal for Ctrl+C propagation */
	signal?: AbortSignal;
	/** Streaming status callback */
	onUpdate?: (status: string) => void;
}

export interface ChainStage {
	/** Persona name */
	persona: string;
	/** Task description for this stage */
	task: string;
}

export interface OrchestratorResult {
	/** Pipeline ID (e.g. "full-tdd-US-01-20260513-143000") */
	pipelineId: string;
	/** Whether all stages completed successfully */
	success: boolean;
	/** Total pipeline duration in ms */
	totalDurationMs: number;
	/** Total cost across all stages */
	totalCost: number;
	/** Ordered stage records from the manifest */
	stages: import("./pipeline.js").StageRecord[];
	/** Error message if pipeline failed */
	errorMessage?: string;
	/** Pipeline manifest (full read from disk) */
	manifest: import("./pipeline.js").PipelineManifest;
}

// ---------------------------------------------------------------------------
// Default stage task templates
// ---------------------------------------------------------------------------

const STAGE_TEMPLATES: Record<string, (task: string) => string> = {
	planner: (task) =>
		`Read the PRD and technical documentation, then create a detailed implementation plan for: ${task}`,
	tester: (task) =>
		`Read the plan from the previous stage, then write comprehensive failing tests for: ${task}`,
	developer: (task) =>
		`Read the plan and study the failing tests from previous stages, then implement the code to make all tests pass for: ${task}`,
	validator: (task) =>
		`Perform a full audit of the implementation for: ${task}. Read the plan, check the code, run all tests.`,
	tracker: (task) =>
		`Update docs/progress.md to reflect the completed pipeline for: ${task}`,
};

/**
 * Build a default task string for a persona + user task.
 * Falls back to the raw task if no template exists for the persona.
 */
export function buildStageTask(persona: string, userTask: string): string {
	const template = STAGE_TEMPLATES[persona];
	return template ? template(userTask) : userTask;
}

// ---------------------------------------------------------------------------
// {previous} substitution
// ---------------------------------------------------------------------------

/**
 * Build the handoff text from a completed stage's summary.
 * This replaces `{previous}` in the next stage's task.
 */
function buildPreviousContext(summary: StageSummary): string {
	const parts: string[] = [];

	parts.push(`[${summary.personaData.type} output]`);
	parts.push(summary.outputSummary);

	if (summary.outputArtifacts.length > 0) {
		parts.push("");
		parts.push("Artifacts:");
		for (const a of summary.outputArtifacts) {
			parts.push(`  - ${a}`);
		}
	}

	if (summary.hookWarning) {
		parts.push("");
		parts.push(`⚠ Hook warning: ${summary.hookWarning}`);
	}

	return parts.join("\n");
}

// ---------------------------------------------------------------------------
// Core: executeChain
// ---------------------------------------------------------------------------

/**
 * Execute a multi-stage subagent pipeline.
 *
 * For each stage:
 *   1. Mark stage as running in the pipeline manifest
 *   2. Spawn subagent via runSubagent
 *   3. Run stop hook to extract structured summary
 *   4. Advance pipeline (success → next stage, failure → stop)
 *   5. Build {previous} context for next stage
 *
 * @param config - Orchestrator configuration
 * @param taskId - Short task identifier (e.g. "US-01")
 * @param workflowName - Workflow name for the manifest
 * @param stages - Ordered chain stages with persona + task
 * @returns Structured orchestrator result
 */
export async function executeChain(
	config: OrchestratorConfig,
	taskId: string,
	workflowName: string,
	stages: ChainStage[],
): Promise<OrchestratorResult> {
	const { cwd, logsDir, pipelinesDir, signal, onUpdate } = config;

	// Generate unique pipeline ID
	const timestamp = new Date().toISOString().replace(/[-:T.]/g, "").slice(0, 14);
	const pipelineId = `${workflowName}-${taskId}-${timestamp}`;

	// Create pipeline manifest
	const pm = PipelineManager.create({
		pipelineId,
		taskId,
		workflow: workflowName,
		stages: stages.map((s) => s.persona),
		pipelinesDir,
	});

	const startedAt = Date.now();
	let previousContext = "";

	for (let i = 0; i < stages.length; i++) {
		const stage = stages[i];

		// Mark stage as running
		pm.markStageRunning(i);

		// Build task with {previous} substitution
		let task = stage.task;
		if (previousContext) {
			task = task.replace(/\{previous\}/g, previousContext);
		}

		onUpdate?.(`Stage ${i + 1}/${stages.length}: ${stage.persona} — running...`);

		// Spawn subagent
		const result: SubagentResult = await runSubagent({
			persona: stage.persona,
			task,
			taskId,
			cwd,
			logsDir,
			signal,
		});

		// Run stop hook
		const hook = getStopHook(stage.persona);
		const summary = hook(result);

		// Determine stage status
		const stageStatus =
			result.exitCode === 0 && result.stopReason !== "error" && result.stopReason !== "aborted"
				? "completed"
				: result.stopReason === "aborted"
					? "aborted"
					: "failed";

		// Advance pipeline
		const advanceResult = pm.advanceStage(i, {
			status: stageStatus,
			outputSummary: summary.outputSummary,
			outputArtifacts: summary.outputArtifacts,
			personaData: summary.personaData,
			durationMs: result.traceSummary.totalDurationMs,
			turns: result.usage.turns,
			cost: result.usage.cost,
			traceLog: result.traceSummary.traceLog,
			hookWarning: summary.hookWarning,
			errorMessage: result.errorMessage,
		});

		// Build context for next stage
		if (stageStatus === "completed") {
			previousContext = buildPreviousContext(summary);
		}

		// Handle failure / abort
		if (stageStatus !== "completed") {
			const errorMsg = result.errorMessage || result.stderr || `Stage ${stage.persona} ${stageStatus}`;
			onUpdate?.(`Pipeline ${stageStatus}: ${errorMsg}`);

			return {
				pipelineId,
				success: false,
				totalDurationMs: Date.now() - startedAt,
				totalCost: pm.getManifest().stages.reduce((sum, s) => sum + (s.cost || 0), 0),
				stages: [...pm.getManifest().stages],
				errorMessage: errorMsg,
				manifest: { ...pm.getManifest() },
			};
		}

		onUpdate?.(`Stage ${i + 1}/${stages.length}: ${stage.persona} — completed`);

		// Check if this was the last stage
		if (advanceResult.isLast) {
			break;
		}
	}

	const finalManifest = pm.getManifest();
	const totalDuration = Date.now() - startedAt;
	const totalCost = finalManifest.stages.reduce((sum, s) => sum + (s.cost || 0), 0);

	onUpdate?.(`Pipeline completed: ${pipelineId}`);

	return {
		pipelineId,
		success: true,
		totalDurationMs: totalDuration,
		totalCost,
		stages: [...finalManifest.stages],
		manifest: { ...finalManifest },
	};
}

// ---------------------------------------------------------------------------
// Convenience: executeWorkflow
// ---------------------------------------------------------------------------

/**
 * Execute a named workflow with default stage task templates.
 *
 * Discovers the workflow by name, builds ChainStages using
 * `buildStageTask()` for each persona, and calls `executeChain()`.
 *
 * @returns OrchestratorResult, or an error result if workflow not found.
 */
export async function executeWorkflow(
	config: OrchestratorConfig,
	workflowName: string,
	taskId: string,
	userTask: string,
): Promise<OrchestratorResult> {
	const workflow = findWorkflow(config.cwd, workflowName);

	if (!workflow) {
		const fakePipelineId = `${workflowName}-${taskId}-error`;
		return {
			pipelineId: fakePipelineId,
			success: false,
			totalDurationMs: 0,
			totalCost: 0,
			stages: [],
			errorMessage: `Workflow "${workflowName}" not found. Available workflows are listed in .pi/workflows/.`,
			manifest: {
				pipelineId: fakePipelineId,
				taskId,
				workflow: workflowName,
				status: "failed",
				startedAt: new Date().toISOString(),
				currentStageIndex: 0,
				stages: [],
			},
		};
	}

	// Build chain stages from workflow definition
	const stages: ChainStage[] = workflow.stages.map((persona) => ({
		persona,
		task: buildStageTask(persona, userTask),
	}));

	return executeChain(config, taskId, workflowName, stages);
}
