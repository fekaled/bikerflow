/**
 * Test script for Phase 5 — Dashboard Module
 *
 * Run: npx jiti .pi/extensions/bikerflow-subagents/test-dashboard.ts
 *
 * Exercises all rendering functions with a mock theme:
 *   - renderPipelineStatus (running, completed, failed pipelines)
 *   - renderTraceLog (multi-turn trace with tool calls)
 *   - renderManifest (structured key-value view)
 *   - renderSummary (aggregate stats)
 *   - findTraceLog (file discovery)
 */

import * as fs from "node:fs";
import * as os from "node:os";
import * as path from "node:path";
import {
	findTraceLog,
	readTraceLog,
	renderManifest,
	renderPipelineStatus,
	renderSummary,
	renderTraceLog,
} from "./dashboard.js";
import type { DashboardTheme } from "./dashboard.js";
import type { PipelineManifest, StageRecord } from "./pipeline.js";
import type { TraceEvent } from "./observability.js";

// ---------------------------------------------------------------------------
// Mock theme — strips ANSI, prefixes with color name for assertions
// ---------------------------------------------------------------------------

const mockTheme: DashboardTheme = {
	fg: (color: string, text: string) => `<${color}>${text}</${color}>`,
	bg: (color: string, text: string) => `<bg:${color}>${text}</bg:${color}>`,
	bold: (text: string) => `<b>${text}</b>`,
};

// Strip theme tags for plain-text assertions
function strip(text: string): string {
	return text.replace(/<\/?[^>]+>/g, "");
}

// ---------------------------------------------------------------------------
// Test harness
// ---------------------------------------------------------------------------

let testsPassed = 0;
let testsFailed = 0;

function assert(condition: boolean, message: string): void {
	if (condition) {
		testsPassed++;
		console.log(`  ✅ ${message}`);
	} else {
		testsFailed++;
		console.error(`  ❌ ${message}`);
	}
}

function assertIncludes(text: string, substring: string, label: string): void {
	if (text.includes(substring)) {
		testsPassed++;
		console.log(`  ✅ ${label}`);
	} else {
		testsFailed++;
		console.error(`  ❌ ${label}`);
		console.error(`     expected to include: "${substring}"`);
		console.error(`     actual: "${text.slice(0, 200)}..."`);
	}
}

function assertNotIncludes(text: string, substring: string, label: string): void {
	if (!text.includes(substring)) {
		testsPassed++;
		console.log(`  ✅ ${label}`);
	} else {
		testsFailed++;
		console.error(`  ❌ ${label}`);
		console.error(`     expected NOT to include: "${substring}"`);
	}
}

function renderToText(container: { render(width: number): string[] }): string {
	return container.render(120).join("\n");
}

function createTempDir(): string {
	return fs.mkdtempSync(path.join(os.tmpdir(), "bikerflow-test-dash-"));
}

// ---------------------------------------------------------------------------
// Test data factories
// ---------------------------------------------------------------------------

function makeManifest(overrides?: Partial<PipelineManifest>): PipelineManifest {
	const now = new Date().toISOString();
	return {
		pipelineId: "tdd-US-01-20260513-143000",
		taskId: "US-01",
		workflow: "full-tdd",
		status: "running",
		startedAt: now,
		currentStageIndex: 2,
		stages: [
			{
				persona: "planner",
				status: "completed",
				startedAt: now,
				completedAt: now,
				durationMs: 25000,
				turns: 3,
				cost: 0.087,
				traceLog: "/tmp/planner-trace.jsonl",
				outputSummary: "Plan saved to docs/plans/US-01.md",
				outputArtifacts: ["docs/plans/US-01.md"],
			},
			{
				persona: "tester",
				status: "completed",
				startedAt: now,
				completedAt: now,
				durationMs: 49000,
				turns: 5,
				cost: 0.142,
				traceLog: "/tmp/tester-trace.jsonl",
				outputSummary: "12 tests RED across 3 files",
				outputArtifacts: [
					"tests/Feature/ShiftTest.php",
					"tests/Unit/PayoutTest.php",
				],
			},
			{
				persona: "developer",
				status: "running",
				startedAt: now,
			},
			{
				persona: "validator",
				status: "pending",
			},
			{
				persona: "tracker",
				status: "pending",
			},
		],
		...overrides,
	};
}

function makeTraceEvents(): TraceEvent[] {
	return [
		{ ts: "2026-05-13T14:30:00.000Z", event: "agent_start", persona: "developer", task: "US-01", taskId: "US-01" },
		{ ts: "2026-05-13T14:30:00.100Z", event: "turn_start", turn: 1 },
		{ ts: "2026-05-13T14:30:00.150Z", event: "message_start", role: "assistant", turn: 1 },
		{ ts: "2026-05-13T14:30:00.500Z", event: "tool_execution_start", toolCallId: "tc-1", tool: "read", args: { path: "docs/plans/US-01.md" }, turn: 1 },
		{ ts: "2026-05-13T14:30:01.100Z", event: "tool_execution_end", toolCallId: "tc-1", tool: "read", success: true, durationMs: 600, turn: 1 },
		{ ts: "2026-05-13T14:30:01.500Z", event: "tool_execution_start", toolCallId: "tc-2", tool: "read", args: { path: "tests/Feature/ShiftTest.php" }, turn: 1 },
		{ ts: "2026-05-13T14:30:01.620Z", event: "tool_execution_end", toolCallId: "tc-2", tool: "read", success: true, durationMs: 120, turn: 1 },
		{ ts: "2026-05-13T14:30:05.000Z", event: "message_end", role: "assistant", turn: 1, tokens: { input: 2500, output: 800, cost: 0.035 } },
		{ ts: "2026-05-13T14:30:05.100Z", event: "turn_end", turn: 1 },
		{ ts: "2026-05-13T14:30:05.200Z", event: "turn_start", turn: 2 },
		{ ts: "2026-05-13T14:30:05.300Z", event: "message_start", role: "assistant", turn: 2 },
		{ ts: "2026-05-13T14:30:05.500Z", event: "tool_execution_start", toolCallId: "tc-3", tool: "write", args: { path: "database/migrations/create_shifts_table.php" }, turn: 2 },
		{ ts: "2026-05-13T14:30:05.545Z", event: "tool_execution_end", toolCallId: "tc-3", tool: "write", success: true, durationMs: 45, turn: 2 },
		{ ts: "2026-05-13T14:30:05.700Z", event: "tool_execution_start", toolCallId: "tc-4", tool: "bash", args: { command: "php artisan migrate" }, turn: 2 },
		{ ts: "2026-05-13T14:30:07.800Z", event: "tool_execution_end", toolCallId: "tc-4", tool: "bash", success: true, durationMs: 2100, turn: 2 },
		{ ts: "2026-05-13T14:30:10.000Z", event: "message_end", role: "assistant", turn: 2, tokens: { input: 3000, output: 600, cost: 0.025 } },
		{ ts: "2026-05-13T14:30:10.100Z", event: "turn_end", turn: 2 },
		{ ts: "2026-05-13T14:30:30.000Z", event: "agent_end", exitCode: 0, stopReason: "end_turn", totalTurns: 2, totalDurationMs: 30000, totalCost: 0.06, output: "Done" },
	];
}

// ---------------------------------------------------------------------------
// Test: renderPipelineStatus — running pipeline
// ---------------------------------------------------------------------------

function testPipelineStatusRunning(): void {
	console.log("\n📦 renderPipelineStatus — running pipeline");
	const manifest = makeManifest();
	const output = renderToText(renderPipelineStatus(manifest, mockTheme));

	assertIncludes(output, "Pipeline: tdd-US-01-20260513-143000", "shows pipeline ID");
	assertIncludes(output, "Task: US-01", "shows task ID");
	assertIncludes(output, "Workflow: full-tdd", "shows workflow");
	assertIncludes(output, "Status: running", "shows running status");
	assertIncludes(output, "stage 3/5", "shows stage progress");
	assertIncludes(output, "planner", "shows planner persona");
	assertIncludes(output, "tester", "shows tester persona");
	assertIncludes(output, "developer", "shows developer persona");
	assertIncludes(output, "validator", "shows validator persona");
	assertIncludes(output, "tracker", "shows tracker persona");
	assertIncludes(output, "running", "shows running indicator");
	assertIncludes(output, "pending", "shows pending indicator");
	assertIncludes(output, "Total so far", "shows totals");
	assertIncludes(output, "25s", "shows planner duration");
	assertIncludes(output, "$0.087", "shows planner cost");
}

// ---------------------------------------------------------------------------
// Test: renderPipelineStatus — completed pipeline
// ---------------------------------------------------------------------------

function testPipelineStatusCompleted(): void {
	console.log("\n📦 renderPipelineStatus — completed pipeline");
	const now = new Date().toISOString();
	const manifest = makeManifest({
		status: "completed",
		completedAt: now,
		currentStageIndex: 4,
		stages: [
			{ persona: "planner", status: "completed", durationMs: 25000, turns: 3, cost: 0.087, outputSummary: "Plan done", startedAt: now, completedAt: now },
			{ persona: "developer", status: "completed", durationMs: 192000, turns: 12, cost: 0.842, outputSummary: "All tests passing", startedAt: now, completedAt: now },
		],
	});
	const output = renderToText(renderPipelineStatus(manifest, mockTheme));

	assertIncludes(output, "Status: completed", "shows completed status");
	assertIncludes(output, "3m12s", "shows developer duration");
	assertIncludes(output, "12t", "shows developer turns");
}

// ---------------------------------------------------------------------------
// Test: renderPipelineStatus — failed pipeline with hook warning
// ---------------------------------------------------------------------------

function testPipelineStatusFailed(): void {
	console.log("\n📦 renderPipelineStatus — failed with hook warning");
	const now = new Date().toISOString();
	const manifest = makeManifest({
		status: "failed",
		completedAt: now,
		currentStageIndex: 1,
		stages: [
			{ persona: "planner", status: "completed", durationMs: 25000, turns: 3, cost: 0.087, outputSummary: "Plan done", startedAt: now, completedAt: now },
			{ persona: "tester", status: "failed", durationMs: 5000, turns: 1, errorMessage: "Permission denied", startedAt: now, completedAt: now, hookWarning: "Could not parse output" },
		],
	});
	const output = renderToText(renderPipelineStatus(manifest, mockTheme));

	assertIncludes(output, "Status: failed", "shows failed status");
	assertIncludes(output, "Could not parse output", "shows hook warning");
}

// ---------------------------------------------------------------------------
// Test: renderTraceLog — multi-turn trace
// ---------------------------------------------------------------------------

function testTraceLog(): void {
	console.log("\n📦 renderTraceLog — multi-turn trace");
	const events = makeTraceEvents();
	const output = renderToText(renderTraceLog(events, "developer", "US-01", mockTheme));

	assertIncludes(output, "developer-US-01", "shows trace header");
	assertIncludes(output, "Persona: developer", "shows persona");
	assertIncludes(output, "Turns: 2", "shows total turns");
	assertIncludes(output, "Turn 1:", "shows turn 1");
	assertIncludes(output, "Turn 2:", "shows turn 2");
	assertIncludes(output, "docs/plans/US-01.md", "shows read path");
	assertIncludes(output, "php artisan migrate", "shows bash command");
	assertIncludes(output, "600ms", "shows tool duration");
}

// ---------------------------------------------------------------------------
// Test: renderTraceLog — many turns truncated
// ---------------------------------------------------------------------------

function testTraceLogTruncation(): void {
	console.log("\n📦 renderTraceLog — turn truncation");
	const events: TraceEvent[] = [
		{ ts: "2026-05-13T14:30:00.000Z", event: "agent_start", persona: "developer", task: "US-01", taskId: "US-01" },
	];

	// Generate 15 turns
	for (let i = 1; i <= 15; i++) {
		events.push({ ts: `2026-05-13T14:30:0${i}.000Z`, event: "turn_start", turn: i });
		events.push({
			ts: `2026-05-13T14:30:0${i}.500Z`, event: "tool_execution_start",
			toolCallId: `tc-${i}`, tool: "bash", args: { command: `echo ${i}` }, turn: i,
		});
		events.push({
			ts: `2026-05-13T14:30:0${i}.600Z`, event: "tool_execution_end",
			toolCallId: `tc-${i}`, tool: "bash", success: true, durationMs: 100, turn: i,
		});
		events.push({ ts: `2026-05-13T14:30:0${i}.700Z`, event: "turn_end", turn: i });
	}
	events.push({
		ts: "2026-05-13T14:31:00.000Z", event: "agent_end",
		exitCode: 0, stopReason: "end_turn", totalTurns: 15, totalDurationMs: 60000, totalCost: 0.5,
	});

	const output = renderToText(renderTraceLog(events, "developer", "US-01", mockTheme));

	assertIncludes(output, "Showing last 10 of 15 turns", "shows truncation notice");
	assertIncludes(output, "Turn 6:", "shows turn 6 (first of last 10)");
	assertNotIncludes(output, "Turn 1:", "hides turn 1 (truncated)");
	assertIncludes(output, "Turn 15:", "shows last turn");
}

// ---------------------------------------------------------------------------
// Test: renderTraceLog — empty trace
// ---------------------------------------------------------------------------

function testTraceLogEmpty(): void {
	console.log("\n📦 renderTraceLog — empty trace");
	const output = renderToText(renderTraceLog([], "planner", "US-01", mockTheme));

	assertIncludes(output, "No turn data", "shows empty message");
}

// ---------------------------------------------------------------------------
// Test: renderManifest — structured view
// ---------------------------------------------------------------------------

function testManifest(): void {
	console.log("\n📦 renderManifest — structured view");
	const now = new Date().toISOString();
	const manifest = makeManifest({
		stages: [
			{
				persona: "planner",
				status: "completed",
				startedAt: now,
				completedAt: now,
				durationMs: 25000,
				turns: 3,
				cost: 0.087,
				traceLog: "/tmp/trace.jsonl",
				outputSummary: "Plan saved to docs/plans/US-01.md",
				outputArtifacts: ["docs/plans/US-01.md"],
				personaData: {
					type: "planner",
					data: { planFilePath: "docs/plans/US-01.md", complexity: "Medium" },
				},
			},
			{
				persona: "developer",
				status: "running",
				startedAt: now,
			},
		],
		currentStageIndex: 1,
	});

	const output = renderToText(renderManifest(manifest, mockTheme));

	assertIncludes(output, "Pipeline Manifest: tdd-US-01-20260513-143000", "shows manifest title");
	assertIncludes(output, "Pipeline ID", "shows Pipeline ID field");
	assertIncludes(output, "Task ID", "shows Task ID field");
	assertIncludes(output, "Workflow", "shows Workflow field");
	assertIncludes(output, "Stages (2)", "shows stage count");
	assertIncludes(output, "plan: docs/plans/US-01.md", "shows persona data plan path");
	assertIncludes(output, "complexity: Medium", "shows persona data complexity");
	assertIncludes(output, "Artifacts", "shows artifacts label");
	assertIncludes(output, "docs/plans/US-01.md", "shows artifact file");
}

// ---------------------------------------------------------------------------
// Test: renderManifest — failed stage with error
// ---------------------------------------------------------------------------

function testManifestFailedStage(): void {
	console.log("\n📦 renderManifest — failed stage");
	const now = new Date().toISOString();
	const manifest = makeManifest({
		status: "failed",
		completedAt: now,
		currentStageIndex: 0,
		stages: [
			{
				persona: "planner",
				status: "failed",
				startedAt: now,
				completedAt: now,
				errorMessage: "PRD file not found",
				hookWarning: "Could not extract plan path",
			},
		],
	});

	const output = renderToText(renderManifest(manifest, mockTheme));

	assertIncludes(output, "Error", "shows Error label");
	assertIncludes(output, "PRD file not found", "shows error message");
	assertIncludes(output, "Hook Warning", "shows Hook Warning label");
	assertIncludes(output, "Could not extract plan path", "shows hook warning text");
}

// ---------------------------------------------------------------------------
// Test: renderSummary — aggregate stats
// ---------------------------------------------------------------------------

function testSummary(): void {
	console.log("\n📦 renderSummary — aggregate stats");
	const now = new Date().toISOString();

	const manifests: PipelineManifest[] = [
		{
			pipelineId: "tdd-US-01",
			taskId: "US-01",
			workflow: "full-tdd",
			status: "completed",
			startedAt: now,
			stages: [
				{ persona: "planner", status: "completed", durationMs: 22000, turns: 3, cost: 0.09, startedAt: now, completedAt: now },
				{ persona: "developer", status: "completed", durationMs: 180000, turns: 10, cost: 0.80, startedAt: now, completedAt: now },
			],
			currentStageIndex: 1,
		},
		{
			pipelineId: "tdd-US-02",
			taskId: "US-02",
			workflow: "full-tdd",
			status: "failed",
			startedAt: now,
			stages: [
				{ persona: "planner", status: "completed", durationMs: 28000, turns: 4, cost: 0.10, startedAt: now, completedAt: now },
				{ persona: "tester", status: "failed", durationMs: 5000, turns: 1, cost: 0.01, startedAt: now, completedAt: now },
			],
			currentStageIndex: 1,
		},
	];

	const output = renderToText(renderSummary(manifests, mockTheme));

	assertIncludes(output, "BikerFlow Agent Summary", "shows header");
	assertIncludes(output, "1 completed", "shows completed count");
	assertIncludes(output, "1 failed", "shows failed count");
	assertIncludes(output, "Per Persona:", "shows persona section");
	assertIncludes(output, "planner", "shows planner stats");
	assertIncludes(output, "2 runs", "shows planner run count");
	assertIncludes(output, "developer", "shows developer stats");
	assertIncludes(output, "Total:", "shows grand total");
	assertIncludes(output, "subagent runs", "shows total runs label");
}

// ---------------------------------------------------------------------------
// Test: renderSummary — empty
// ---------------------------------------------------------------------------

function testSummaryEmpty(): void {
	console.log("\n📦 renderSummary — empty");
	const output = renderToText(renderSummary([], mockTheme));

	assertIncludes(output, "No pipelines found", "shows empty message");
}

// ---------------------------------------------------------------------------
// Test: renderSummary — with since filter
// ---------------------------------------------------------------------------

function testSummarySinceFilter(): void {
	console.log("\n📦 renderSummary — since filter");
	const now = new Date();
	const old = new Date(now.getTime() - 100 * 24 * 60 * 60 * 1000); // 100 days ago

	const manifests: PipelineManifest[] = [
		{
			pipelineId: "old",
			taskId: "T1",
			workflow: "w",
			status: "completed",
			startedAt: old.toISOString(),
			stages: [
				{ persona: "planner", status: "completed", durationMs: 1000, turns: 1, cost: 0.01, startedAt: old.toISOString(), completedAt: old.toISOString() },
			],
			currentStageIndex: 0,
		},
		{
			pipelineId: "new",
			taskId: "T2",
			workflow: "w",
			status: "completed",
			startedAt: now.toISOString(),
			stages: [
				{ persona: "planner", status: "completed", durationMs: 2000, turns: 2, cost: 0.02, startedAt: now.toISOString(), completedAt: now.toISOString() },
			],
			currentStageIndex: 0,
		},
	];

	// Default: last 30 days → only "new"
	const output30d = renderToText(renderSummary(manifests, mockTheme));
	assertIncludes(output30d, "1 completed", "30d: shows 1 completed");
	assertIncludes(output30d, "1 runs", "30d: shows 1 planner run");

	// All time (since = undefined, but pass a very old date)
	const allTime = renderToText(renderSummary(manifests, mockTheme, new Date(0)));
	assertIncludes(allTime, "2 completed", "all-time: shows 2 completed");
	assertIncludes(allTime, "2 runs", "all-time: shows 2 planner runs");
}

// ---------------------------------------------------------------------------
// Test: findTraceLog — file discovery
// ---------------------------------------------------------------------------

function testFindTraceLog(): void {
	console.log("\n📦 findTraceLog — file discovery");
	const tmpDir = createTempDir();

	// Create fake trace files
	fs.writeFileSync(path.join(tmpDir, "2026-05-12-planner-US-01.jsonl"), '{"event":"agent_start"}\n');
	fs.writeFileSync(path.join(tmpDir, "2026-05-13-planner-US-01.jsonl"), '{"event":"agent_start"}\n');
	fs.writeFileSync(path.join(tmpDir, "2026-05-13-tester-US-01.jsonl"), '{"event":"agent_start"}\n');
	fs.writeFileSync(path.join(tmpDir, "2026-05-13-planner-US-02.jsonl"), '{"event":"agent_start"}\n');

	// Find most recent planner trace
	const plannerTrace = findTraceLog("planner", tmpDir);
	assert(plannerTrace !== null, "findTraceLog: finds planner trace");
	assert(plannerTrace!.includes("2026-05-13"), "findTraceLog: returns most recent");
	assert(plannerTrace!.includes("planner"), "findTraceLog: returns planner file");

	// Find with taskId filter
	const plannerUS01 = findTraceLog("planner", tmpDir, "US-01");
	assert(plannerUS01 !== null, "findTraceLog: finds with taskId filter");
	assert(!plannerUS01!.includes("US-02"), "findTraceLog: filters by taskId");

	// Find tester
	const testerTrace = findTraceLog("tester", tmpDir);
	assert(testerTrace !== null, "findTraceLog: finds tester trace");
	assert(testerTrace!.includes("tester"), "findTraceLog: returns tester file");

	// Not found
	const missing = findTraceLog("validator", tmpDir);
	assertEqual(missing, null, "findTraceLog: returns null for missing persona");

	fs.rmSync(tmpDir, { recursive: true, force: true });
}

// ---------------------------------------------------------------------------
// Test: readTraceLog — parse JSONL
// ---------------------------------------------------------------------------

function testReadTraceLog(): void {
	console.log("\n📦 readTraceLog — parse JSONL");
	const tmpDir = createTempDir();
	const filePath = path.join(tmpDir, "test-trace.jsonl");

	fs.writeFileSync(filePath, [
		'{"ts":"2026-05-13T14:30:00Z","event":"agent_start"}',
		'{"ts":"2026-05-13T14:30:01Z","event":"turn_start","turn":1}',
		'{"ts":"2026-05-13T14:30:02Z","event":"turn_end","turn":1}',
		"", // trailing newline produces empty line
	].join("\n"));

	const events = readTraceLog(filePath);
	assertEqual(events.length, 3, "readTraceLog: parses 3 events");
	assertEqual(events[0].event, "agent_start", "readTraceLog: first event type");
	assertEqual(events[1].turn, 1, "readTraceLog: turn field preserved");

	// Non-existent file
	const missing = readTraceLog("/nonexistent/path.jsonl");
	assertEqual(missing.length, 0, "readTraceLog: returns [] for missing file");

	fs.rmSync(tmpDir, { recursive: true, force: true });
}

// ---------------------------------------------------------------------------
// Test: renderPipelineStatus — empty stages
// ---------------------------------------------------------------------------

function testPipelineStatusEmptyStages(): void {
	console.log("\n📦 renderPipelineStatus — empty stages");
	const now = new Date().toISOString();
	const manifest = makeManifest({
		status: "pending",
		stages: [],
		currentStageIndex: 0,
	});
	const output = renderToText(renderPipelineStatus(manifest, mockTheme));

	assertIncludes(output, "stage 0/0", "handles empty stages");
	assertNotIncludes(output, "Total so far", "no totals for empty stages");
}

// ---------------------------------------------------------------------------
// Test: renderManifest — persona data variants
// ---------------------------------------------------------------------------

function testManifestPersonaData(): void {
	console.log("\n📦 renderManifest — all persona data types");
	const now = new Date().toISOString();

	const manifest: PipelineManifest = {
		pipelineId: "test-personas",
		taskId: "T1",
		workflow: "full-tdd",
		status: "completed",
		startedAt: now,
		completedAt: now,
		currentStageIndex: 4,
		stages: [
			{ persona: "planner", status: "completed", startedAt: now, completedAt: now, personaData: { type: "planner", data: { planFilePath: "docs/plans/x.md", complexity: "Complex" } } },
			{ persona: "tester", status: "completed", startedAt: now, completedAt: now, personaData: { type: "tester", data: { testFiles: ["tests/a.php"], redCount: 8 } } },
			{ persona: "developer", status: "completed", startedAt: now, completedAt: now, personaData: { type: "developer", data: { filesCreated: ["app/X.php"], filesModified: [], testsPassing: true } } },
			{ persona: "validator", status: "completed", startedAt: now, completedAt: now, personaData: { type: "validator", data: { verdict: "PASS", findings: ["No issues"] } } },
			{ persona: "tracker", status: "completed", startedAt: now, completedAt: now, personaData: { type: "tracker", data: { progressUpdated: true } } },
		],
	};

	const output = renderToText(renderManifest(manifest, mockTheme));

	assertIncludes(output, "plan: docs/plans/x.md", "shows planner plan");
	assertIncludes(output, "complexity: Complex", "shows planner complexity");
	assertIncludes(output, "test files: 1", "shows tester file count");
	assertIncludes(output, "RED: 8", "shows tester red count");
	assertIncludes(output, "created: 1 files", "shows developer files");
	assertIncludes(output, "tests:", "shows developer tests status");
	assertIncludes(output, "verdict: PASS", "shows validator verdict");
	assertIncludes(output, "findings: 1", "shows validator findings count");
	assertIncludes(output, "progress.md: updated", "shows tracker status");
}

// ---------------------------------------------------------------------------
// Helpers for assertions (reused from earlier)
// ---------------------------------------------------------------------------

function assertEqual(actual: unknown, expected: unknown, label: string): void {
	const match = JSON.stringify(actual) === JSON.stringify(expected);
	if (match) {
		testsPassed++;
		console.log(`  ✅ ${label}`);
	} else {
		testsFailed++;
		console.error(`  ❌ ${label}`);
		console.error(`     expected: ${JSON.stringify(expected)}`);
		console.error(`     actual:   ${JSON.stringify(actual)}`);
	}
}

// ---------------------------------------------------------------------------
// Run all tests
// ---------------------------------------------------------------------------

console.log("═══════════════════════════════════════");
console.log("🧪 Phase 5 Tests — Dashboard Module");
console.log("═══════════════════════════════════════");

testPipelineStatusRunning();
testPipelineStatusCompleted();
testPipelineStatusFailed();
testTraceLog();
testTraceLogTruncation();
testTraceLogEmpty();
testManifest();
testManifestFailedStage();
testManifestPersonaData();
testPipelineStatusEmptyStages();
testSummary();
testSummaryEmpty();
testSummarySinceFilter();
testFindTraceLog();
testReadTraceLog();

console.log("\n═══════════════════════════════════════");
console.log(`Results: ${testsPassed} passed, ${testsFailed} failed`);
console.log("═══════════════════════════════════════");

if (testsFailed > 0) {
	process.exit(1);
}
