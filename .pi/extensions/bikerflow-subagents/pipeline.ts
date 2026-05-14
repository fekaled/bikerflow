/**
 * Pipeline Manager — chain-aware manifest lifecycle
 *
 * Manages pipeline manifest JSON files in `docs/agents/pipelines/`.
 * Each pipeline tracks a multi-stage subagent workflow (e.g., full TDD).
 *
 * Chain-aware: knows stage order and can advance between stages,
 * returning the next persona to spawn.
 *
 * Lifecycle:
 *   PipelineManager.create(...)  → write manifest, return instance
 *   pm.markStageRunning(i)       → mark stage as active
 *   pm.advanceStage(i, summary)  → complete stage, move to next
 *   pm.finalize(...)             → close pipeline
 */

import * as fs from "node:fs";
import * as path from "node:path";
import type { PersonaData } from "./hooks.js";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export type PipelineStatus = "pending" | "running" | "completed" | "failed" | "aborted";
export type StageStatus = "pending" | "running" | "completed" | "failed" | "aborted";

/** A single stage within a pipeline. */
export interface StageRecord {
	persona: string;
	status: StageStatus;
	startedAt?: string;
	completedAt?: string;
	durationMs?: number;
	turns?: number;
	cost?: number;
	traceLog?: string;
	outputSummary?: string;
	outputArtifacts?: string[];
	errorMessage?: string;
	hookWarning?: string;
	personaData?: PersonaData;
}

/** Full pipeline manifest — persisted as JSON. */
export interface PipelineManifest {
	pipelineId: string;
	taskId: string;
	workflow: string;
	status: PipelineStatus;
	startedAt: string;
	completedAt?: string;
	currentStageIndex: number;
	stages: StageRecord[];
}

/** Result of advancing a stage. */
export interface AdvanceResult {
	/** Next persona to spawn, if pipeline continues. */
	nextPersona?: string;
	/** Index of the next stage. */
	nextStageIndex?: number;
	/** True when the pipeline has no more stages (or was stopped by failure). */
	isLast: boolean;
}

// ---------------------------------------------------------------------------
// PipelineManager
// ---------------------------------------------------------------------------

export class PipelineManager {
	private readonly filePath: string;
	private manifest: PipelineManifest;

	private constructor(filePath: string, manifest: PipelineManifest) {
		this.filePath = filePath;
		this.manifest = manifest;
	}

	// -----------------------------------------------------------------------
	// Static factories
	// -----------------------------------------------------------------------

	/**
	 * Create a new pipeline manifest on disk.
	 *
	 * @param params.pipelineId - Unique ID (e.g. "tdd-US-01-20260513-143000")
	 * @param params.taskId     - Task identifier (e.g. "US-01")
	 * @param params.workflow   - Workflow name (e.g. "full-tdd")
	 * @param params.stages     - Ordered list of persona names
	 * @param params.pipelinesDir - Directory for manifest files
	 */
	static create(params: {
		pipelineId: string;
		taskId: string;
		workflow: string;
		stages: string[];
		pipelinesDir: string;
	}): PipelineManager {
		const filePath = path.join(params.pipelinesDir, `${params.pipelineId}.json`);
		const now = new Date().toISOString();

		const manifest: PipelineManifest = {
			pipelineId: params.pipelineId,
			taskId: params.taskId,
			workflow: params.workflow,
			status: "running",
			startedAt: now,
			currentStageIndex: 0,
			stages: params.stages.map((persona) => ({
				persona,
				status: "pending" as StageStatus,
			})),
		};

		// Mark first stage as running
		if (manifest.stages.length > 0) {
			manifest.stages[0].status = "running";
			manifest.stages[0].startedAt = now;
		}

		fs.mkdirSync(params.pipelinesDir, { recursive: true });
		PipelineManager.writeManifest(filePath, manifest);

		return new PipelineManager(filePath, manifest);
	}

	/**
	 * Read an existing pipeline manifest from disk.
	 * Returns `null` if the file doesn't exist or is invalid.
	 */
	static read(pipelineId: string, pipelinesDir: string): PipelineManager | null {
		const filePath = path.join(pipelinesDir, `${pipelineId}.json`);
		try {
			const raw = fs.readFileSync(filePath, "utf-8");
			const manifest = JSON.parse(raw) as PipelineManifest;
			return new PipelineManager(filePath, manifest);
		} catch {
			return null;
		}
	}

	/**
	 * Read a manifest by full file path (for dashboard use).
	 */
	static readPath(filePath: string): PipelineManager | null {
		try {
			const raw = fs.readFileSync(filePath, "utf-8");
			const manifest = JSON.parse(raw) as PipelineManifest;
			return new PipelineManager(filePath, manifest);
		} catch {
			return null;
		}
	}

	/**
	 * List all pipeline manifests in a directory, newest first.
	 */
	static list(pipelinesDir: string): PipelineManifest[] {
		try {
			const entries = fs.readdirSync(pipelinesDir);
			const manifests: PipelineManifest[] = [];
			for (const entry of entries) {
				if (!entry.endsWith(".json")) continue;
				try {
					const raw = fs.readFileSync(path.join(pipelinesDir, entry), "utf-8");
					manifests.push(JSON.parse(raw) as PipelineManifest);
				} catch {
					/* skip corrupt files */
				}
			}
			return manifests.sort(
				(a, b) => new Date(b.startedAt).getTime() - new Date(a.startedAt).getTime(),
			);
		} catch {
			return [];
		}
	}

	// -----------------------------------------------------------------------
	// Accessors
	// -----------------------------------------------------------------------

	/** Read-only view of the current manifest. */
	getManifest(): Readonly<PipelineManifest> {
		return this.manifest;
	}

	get pipelineId(): string {
		return this.manifest.pipelineId;
	}

	get taskId(): string {
		return this.manifest.taskId;
	}

	get workflow(): string {
		return this.manifest.workflow;
	}

	get status(): PipelineStatus {
		return this.manifest.status;
	}

	get currentStageIndex(): number {
		return this.manifest.currentStageIndex;
	}

	get totalStages(): number {
		return this.manifest.stages.length;
	}

	get currentStage(): StageRecord | undefined {
		return this.manifest.stages[this.manifest.currentStageIndex];
	}

	get currentPersona(): string | undefined {
		return this.currentStage?.persona;
	}

	/** Get the full file path of the manifest. */
	get manifestPath(): string {
		return this.filePath;
	}

	/** Check whether the pipeline has finished (completed, failed, or aborted). */
	get isFinished(): boolean {
		return (
			this.manifest.status === "completed" ||
			this.manifest.status === "failed" ||
			this.manifest.status === "aborted"
		);
	}

	// -----------------------------------------------------------------------
	// Stage mutations
	// -----------------------------------------------------------------------

	/**
	 * Mark a stage as running. Updates `currentStageIndex`.
	 * Called by the orchestrator before spawning a subagent.
	 */
	markStageRunning(stageIndex: number): void {
		if (stageIndex < 0 || stageIndex >= this.manifest.stages.length) return;

		const stage = this.manifest.stages[stageIndex];
		stage.status = "running";
		stage.startedAt = new Date().toISOString();
		this.manifest.currentStageIndex = stageIndex;
		this.persist();
	}

	/**
	 * Advance from a stage to the next one in the chain.
	 *
	 * - On success (`status: "completed"`): marks stage done, advances
	 *   `currentStageIndex`, sets next stage as running, returns next persona.
	 * - On failure/abort: marks stage with the status, stops the chain
	 *   (does NOT advance), finalizes the pipeline.
	 *
	 * @returns Info about what comes next (or that the pipeline is done).
	 */
	advanceStage(
		stageIndex: number,
		result: {
			status: "completed" | "failed" | "aborted";
			outputSummary?: string;
			outputArtifacts?: string[];
			personaData?: PersonaData;
			durationMs?: number;
			turns?: number;
			cost?: number;
			traceLog?: string;
			hookWarning?: string;
			errorMessage?: string;
		},
	): AdvanceResult {
		if (stageIndex < 0 || stageIndex >= this.manifest.stages.length) {
			return { isLast: true };
		}

		const stage = this.manifest.stages[stageIndex];
		const now = new Date().toISOString();

		// Fill stage record with result data
		stage.status = result.status;
		stage.completedAt = now;
		stage.outputSummary = result.outputSummary;
		stage.outputArtifacts = result.outputArtifacts;
		stage.personaData = result.personaData;
		stage.durationMs = result.durationMs;
		stage.turns = result.turns;
		stage.cost = result.cost;
		stage.traceLog = result.traceLog;
		stage.hookWarning = result.hookWarning;
		stage.errorMessage = result.errorMessage;

		// Failure / abort → stop chain
		if (result.status !== "completed") {
			this.manifest.status = result.status === "aborted" ? "aborted" : "failed";
			this.manifest.completedAt = now;
			this.persist();
			return { isLast: false };
		}

		// Success → advance to next stage
		const nextIndex = stageIndex + 1;
		if (nextIndex >= this.manifest.stages.length) {
			// All stages done
			this.manifest.status = "completed";
			this.manifest.completedAt = now;
			this.persist();
			return { isLast: true };
		}

		// Set next stage as current and running
		this.manifest.currentStageIndex = nextIndex;
		const nextStage = this.manifest.stages[nextIndex];
		nextStage.status = "running";
		nextStage.startedAt = now;
		this.persist();

		return {
			nextPersona: nextStage.persona,
			nextStageIndex: nextIndex,
			isLast: false,
		};
	}

	/**
	 * Force-finalize the pipeline (e.g., on unrecoverable error or user abort).
	 * Marks any running stage with the given status.
	 */
	finalize(status: PipelineStatus, errorMessage?: string): void {
		this.manifest.status = status;
		this.manifest.completedAt = new Date().toISOString();

		// Mark any running stage as failed/aborted
		for (const stage of this.manifest.stages) {
			if (stage.status === "running") {
				stage.status = status === "aborted" ? "aborted" : "failed";
				stage.completedAt = this.manifest.completedAt;
			}
		}

		if (errorMessage && this.manifest.currentStageIndex < this.manifest.stages.length) {
			this.manifest.stages[this.manifest.currentStageIndex].errorMessage = errorMessage;
		}

		this.persist();
	}

	// -----------------------------------------------------------------------
	// Summary formatting
	// -----------------------------------------------------------------------

	/**
	 * Return a formatted text summary of the pipeline.
	 * Phase 5's dashboard module will build richer TUI output on top of this.
	 */
	formatSummary(): string {
		const m = this.manifest;
		const lines: string[] = [];

		lines.push(`Pipeline: ${m.pipelineId}`);
		lines.push(`Task: ${m.taskId}`);
		lines.push(`Workflow: ${m.workflow}`);

		const activeNum = m.stages.findIndex((s) => s.status === "running" || s.status === "pending");
		const displayNum = activeNum >= 0 ? activeNum + 1 : m.stages.length;
		lines.push(`Status: ${m.status} (stage ${displayNum}/${m.stages.length})`);
		lines.push("");

		for (const stage of m.stages) {
			const icon: Record<StageStatus, string> = {
				pending: "·",
				running: "⏳",
				completed: "✓",
				failed: "✗",
				aborted: "⊘",
			}[stage.status];

			const parts = [icon, stage.persona.padEnd(12)];

			if (stage.durationMs !== undefined) {
				parts.push(formatDuration(stage.durationMs).padEnd(6));
			} else {
				parts.push("      ");
			}

			if (stage.turns !== undefined) {
				parts.push(`${stage.turns}t`.padEnd(5));
			} else if (stage.status === "pending") {
				parts.push("     ");
			} else {
				parts.push("     ");
			}

			if (stage.cost !== undefined) {
				parts.push(`$${stage.cost.toFixed(4)}`.padEnd(9));
			} else {
				parts.push("         ");
			}

			if (stage.status === "running") {
				parts.push("... running ...");
			} else if (stage.status === "pending") {
				parts.push("pending");
			} else if (stage.outputSummary) {
				parts.push(`→ ${truncate(stage.outputSummary, 60)}`);
			}

			lines.push(parts.join(" "));

			if (stage.hookWarning) {
				lines.push(`  ⚠ ${stage.hookWarning}`);
			}
		}

		// Totals from completed stages
		const completed = m.stages.filter((s) => s.status === "completed");
		if (completed.length > 0) {
			const totalDuration = completed.reduce((sum, s) => sum + (s.durationMs || 0), 0);
			const totalTurns = completed.reduce((sum, s) => sum + (s.turns || 0), 0);
			const totalCost = completed.reduce((sum, s) => sum + (s.cost || 0), 0);
			lines.push("");
			lines.push(
				`Total so far: ${formatDuration(totalDuration)}  ${totalTurns} turns  $${totalCost.toFixed(4)}`,
			);
		}

		return lines.join("\n");
	}

	// -----------------------------------------------------------------------
	// Private
	// -----------------------------------------------------------------------

	private persist(): void {
		PipelineManager.writeManifest(this.filePath, this.manifest);
	}

	private static writeManifest(filePath: string, manifest: PipelineManifest): void {
		try {
			const json = JSON.stringify(manifest, null, 2);
			fs.writeFileSync(filePath, json, "utf-8");
		} catch (err) {
			// Non-blocking — observability/data layer should never crash the pipeline
			process.stderr.write(`[pipeline] Failed to write manifest: ${err}\n`);
		}
	}
}

// ---------------------------------------------------------------------------
// Helpers
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
