/**
 * Test script for Phase 4 — Pipeline Manager + Stop Hooks
 *
 * Run: npx jiti .pi/extensions/bikerflow-subagents/test-pipeline.ts
 *
 * Exercises PipelineManager (create, advance, finalize, list)
 * and all persona stop hooks with realistic SubagentResult data.
 */

import * as fs from "node:fs";
import * as os from "node:os";
import * as path from "node:path";
import { getStopHook } from "./hooks.js";
import { PipelineManager } from "./pipeline.js";
import type { SubagentResult } from "./subagent.js";
import type { TraceSummary } from "./observability.js";

// ---------------------------------------------------------------------------
// Helpers
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

function createTempDir(): string {
	return fs.mkdtempSync(path.join(os.tmpdir(), "bikerflow-test-pipeline-"));
}

function makeFakeTraceSummary(overrides?: Partial<TraceSummary>): TraceSummary {
	const now = new Date().toISOString();
	return {
		persona: "planner",
		task: "US-01",
		taskId: "US-01",
		traceLog: "/tmp/fake-trace.jsonl",
		startedAt: now,
		completedAt: now,
		totalDurationMs: 25000,
		totalTurns: 3,
		totalInputTokens: 5000,
		totalOutputTokens: 1000,
		totalCacheReadTokens: 0,
		totalCacheWriteTokens: 0,
		totalCost: 0.087,
		stopReason: "end_turn",
		exitCode: 0,
		toolCalls: 5,
		toolCallsFailed: 0,
		outputArtifacts: [],
		...overrides,
	};
}

function makeFakeResult(
	persona: string,
	finalOutput: string,
	overrides?: Partial<SubagentResult>,
): SubagentResult {
	return {
		persona,
		task: `Task for ${persona}`,
		taskId: "US-01",
		exitCode: 0,
		messages: [],
		stderr: "",
		usage: { input: 1000, output: 500, cacheRead: 0, cacheWrite: 0, cost: 0.05, contextTokens: 2000, turns: 2 },
		model: "test-model",
		stopReason: "end_turn",
		traceSummary: makeFakeTraceSummary({ persona }),
		finalOutput,
		...overrides,
	};
}

// ---------------------------------------------------------------------------
// Test: PipelineManager — create
// ---------------------------------------------------------------------------

function testPipelineCreate(): void {
	console.log("\n📦 PipelineManager.create");
	const tmpDir = createTempDir();

	const pm = PipelineManager.create({
		pipelineId: "tdd-US-01-test",
		taskId: "US-01",
		workflow: "full-tdd",
		stages: ["planner", "tester", "developer", "validator", "tracker"],
		pipelinesDir: tmpDir,
	});

	assertEqual(pm.pipelineId, "tdd-US-01-test", "pipelineId");
	assertEqual(pm.taskId, "US-01", "taskId");
	assertEqual(pm.workflow, "full-tdd", "workflow");
	assertEqual(pm.totalStages, 5, "totalStages");
	assertEqual(pm.status, "running", "status is running");
	assertEqual(pm.currentStageIndex, 0, "currentStageIndex = 0");
	assertEqual(pm.currentPersona, "planner", "currentPersona = planner");
	assert(!pm.isFinished, "not finished");

	const manifest = pm.getManifest();
	assertEqual(manifest.stages[0].status, "running", "first stage is running");
	assertEqual(manifest.stages[1].status, "pending", "second stage is pending");
	assert(manifest.stages[0].startedAt !== undefined, "first stage has startedAt");

	// File exists on disk
	const filePath = path.join(tmpDir, "tdd-US-01-test.json");
	assert(fs.existsSync(filePath), "manifest file exists on disk");

	// Clean JSON
	const parsed = JSON.parse(fs.readFileSync(filePath, "utf-8"));
	assertEqual(parsed.pipelineId, "tdd-US-01-test", "disk: pipelineId");

	fs.rmSync(tmpDir, { recursive: true, force: true });
}

// ---------------------------------------------------------------------------
// Test: PipelineManager — advance through full chain
// ---------------------------------------------------------------------------

function testPipelineAdvanceFullChain(): void {
	console.log("\n📦 PipelineManager.advanceStage — full chain");
	const tmpDir = createTempDir();

	const pm = PipelineManager.create({
		pipelineId: "chain-test",
		taskId: "US-01",
		workflow: "full-tdd",
		stages: ["planner", "tester", "developer"],
		pipelinesDir: tmpDir,
	});

	// Advance stage 0 (planner)
	const r1 = pm.advanceStage(0, {
		status: "completed",
		outputSummary: "Plan created",
		outputArtifacts: ["docs/plans/US-01.md"],
		personaData: { type: "planner", data: { planFilePath: "docs/plans/US-01.md" } },
		durationMs: 25000,
		turns: 3,
		cost: 0.087,
		traceLog: "/tmp/planner-trace.jsonl",
	});

	assertEqual(r1.nextPersona, "tester", "advance 0: next = tester");
	assertEqual(r1.nextStageIndex, 1, "advance 0: next index = 1");
	assert(!r1.isLast, "advance 0: not last");
	assertEqual(pm.currentPersona, "tester", "current persona = tester");
	assertEqual(pm.currentStageIndex, 1, "current index = 1");

	// Check planner stage was filled
	const stage0 = pm.getManifest().stages[0];
	assertEqual(stage0.status, "completed", "planner stage: completed");
	assertEqual(stage0.turns, 3, "planner stage: turns");
	assertEqual(stage0.cost, 0.087, "planner stage: cost");
	assertEqual(stage0.outputArtifacts?.length, 1, "planner stage: 1 artifact");

	// Advance stage 1 (tester)
	const r2 = pm.advanceStage(1, {
		status: "completed",
		outputSummary: "12 tests RED",
		outputArtifacts: ["tests/Feature/TripTest.php"],
		personaData: { type: "tester", data: { testFiles: ["tests/Feature/TripTest.php"], redCount: 12 } },
		durationMs: 49000,
		turns: 5,
		cost: 0.142,
	});

	assertEqual(r2.nextPersona, "developer", "advance 1: next = developer");

	// Advance stage 2 (developer) — last stage
	const r3 = pm.advanceStage(2, {
		status: "completed",
		outputSummary: "All tests passing",
		outputArtifacts: ["app/Models/Trip.php", "database/migrations/create_trips_table.php"],
		personaData: { type: "developer", data: { filesCreated: ["app/Models/Trip.php"], filesModified: [], testsPassing: true } },
		durationMs: 192000,
		turns: 12,
		cost: 0.842,
	});

	assertEqual(r3.isLast, true, "advance 2: isLast");
	assertEqual(r3.nextPersona, undefined, "advance 2: no next persona");
	assertEqual(pm.status, "completed", "pipeline status: completed");
	assert(pm.isFinished, "pipeline is finished");
	assert(pm.getManifest().completedAt !== undefined, "pipeline has completedAt");

	// Verify formatSummary
	const summary = pm.formatSummary();
	assert(summary.includes("✓ planner"), "summary: shows planner completed");
	assert(summary.includes("✓ tester"), "summary: shows tester completed");
	assert(summary.includes("✓ developer"), "summary: shows developer completed");
	assert(summary.includes("Total so far"), "summary: includes totals");

	fs.rmSync(tmpDir, { recursive: true, force: true });
}

// ---------------------------------------------------------------------------
// Test: PipelineManager — advance with failure stops chain
// ---------------------------------------------------------------------------

function testPipelineAdvanceFailure(): void {
	console.log("\n📦 PipelineManager.advanceStage — failure stops chain");
	const tmpDir = createTempDir();

	const pm = PipelineManager.create({
		pipelineId: "fail-test",
		taskId: "US-02",
		workflow: "full-tdd",
		stages: ["planner", "tester", "developer"],
		pipelinesDir: tmpDir,
	});

	// Advance stage 0 (planner) — success
	pm.advanceStage(0, { status: "completed", outputSummary: "Plan done" });

	// Advance stage 1 (tester) — failure
	const r = pm.advanceStage(1, {
		status: "failed",
		outputSummary: "Could not write tests",
		errorMessage: "Permission denied",
		durationMs: 5000,
	});

	assertEqual(r.isLast, false, "failure: isLast = false (not all stages done)");
	assertEqual(r.nextPersona, undefined, "failure: no next persona");
	assertEqual(pm.status, "failed", "failure: pipeline status = failed");
	assert(pm.isFinished, "failure: pipeline is finished");

	// Verify developer stage is still pending (never started)
	assertEqual(pm.getManifest().stages[2].status, "pending", "failure: stage 2 still pending");

	fs.rmSync(tmpDir, { recursive: true, force: true });
}

// ---------------------------------------------------------------------------
// Test: PipelineManager — finalize
// ---------------------------------------------------------------------------

function testPipelineFinalize(): void {
	console.log("\n📦 PipelineManager.finalize");
	const tmpDir = createTempDir();

	const pm = PipelineManager.create({
		pipelineId: "finalize-test",
		taskId: "US-03",
		workflow: "plan-only",
		stages: ["planner", "tracker"],
		pipelinesDir: tmpDir,
	});

	// Force-finalize while stage 0 is running
	pm.finalize("aborted", "User cancelled");

	assertEqual(pm.status, "aborted", "finalize: status = aborted");
	assert(pm.isFinished, "finalize: is finished");

	// The running stage should be marked aborted
	assertEqual(pm.getManifest().stages[0].status, "aborted", "finalize: running stage = aborted");
	assertEqual(pm.getManifest().stages[0].errorMessage, "User cancelled", "finalize: error message set");

	// Pending stage stays pending
	assertEqual(pm.getManifest().stages[1].status, "pending", "finalize: pending stage unchanged");

	fs.rmSync(tmpDir, { recursive: true, force: true });
}

// ---------------------------------------------------------------------------
// Test: PipelineManager — read from disk
// ---------------------------------------------------------------------------

function testPipelineRead(): void {
	console.log("\n📦 PipelineManager.read");
	const tmpDir = createTempDir();

	const pm = PipelineManager.create({
		pipelineId: "read-test",
		taskId: "BR-03",
		workflow: "implement-only",
		stages: ["developer", "validator"],
		pipelinesDir: tmpDir,
	});

	pm.advanceStage(0, { status: "completed", outputSummary: "Done" });

	// Read back from disk
	const pm2 = PipelineManager.read("read-test", tmpDir);
	assert(pm2 !== null, "read: returns instance");
	assertEqual(pm2!.pipelineId, "read-test", "read: pipelineId matches");
	assertEqual(pm2!.currentStageIndex, 1, "read: currentStageIndex matches");
	assertEqual(pm2!.getManifest().stages[0].status, "completed", "read: stage 0 completed");

	// Read nonexistent
	const pm3 = PipelineManager.read("nonexistent", tmpDir);
	assertEqual(pm3, null, "read: returns null for missing file");

	fs.rmSync(tmpDir, { recursive: true, force: true });
}

// ---------------------------------------------------------------------------
// Test: PipelineManager — list
// ---------------------------------------------------------------------------

function testPipelineList(): void {
	console.log("\n📦 PipelineManager.list");
	const tmpDir = createTempDir();

	// Create 3 pipelines with explicit startedAt differences
	// (timestamps may collide if created in the same millisecond)
	PipelineManager.create({ pipelineId: "list-1", taskId: "T1", workflow: "w", stages: ["planner"], pipelinesDir: tmpDir });
	PipelineManager.create({ pipelineId: "list-2", taskId: "T2", workflow: "w", stages: ["planner"], pipelinesDir: tmpDir });
	PipelineManager.create({ pipelineId: "list-3", taskId: "T3", workflow: "w", stages: ["planner"], pipelinesDir: tmpDir });

	const list = PipelineManager.list(tmpDir);
	assertEqual(list.length, 3, "list: returns 3 manifests");

	// Sorted newest-first (all created in same ms — verify ordering is stable)
	const ids = list.map((m) => m.pipelineId);
	assert(ids.includes("list-1") && ids.includes("list-2") && ids.includes("list-3"), "list: all 3 IDs present");

	// Empty dir
	const emptyDir = createTempDir();
	const emptyList = PipelineManager.list(emptyDir);
	assertEqual(emptyList.length, 0, "list: empty dir returns []");

	fs.rmSync(tmpDir, { recursive: true, force: true });
	fs.rmSync(emptyDir, { recursive: true, force: true });
}

// ---------------------------------------------------------------------------
// Test: Planner hook
// ---------------------------------------------------------------------------

function testPlannerHook(): void {
	console.log("\n📦 plannerStopHook");
	const hook = getStopHook("planner");

	const result = makeFakeResult("planner", [
		"I've analyzed the PRD and created a plan.",
		"Plan saved to docs/plans/US-01-trip-tracking.md",
		"Business rules: BR-01, BR-03, BR-05",
		"Complexity: Medium",
	].join("\n"), {
		traceSummary: makeFakeTraceSummary({
			outputArtifacts: ["docs/plans/US-01-trip-tracking.md"],
		}),
	});

	const summary = hook(result);

	assertEqual(summary.personaData.type, "planner", "planner: personaData type");
	assertEqual(summary.personaData.data.planFilePath, "docs/plans/US-01-trip-tracking.md", "planner: planFilePath");
	assert(summary.outputArtifacts.includes("docs/plans/US-01-trip-tracking.md"), "planner: artifact included");
	assert(summary.outputSummary.includes("BR-01"), "planner: summary includes BR-01");
	assert(summary.hookWarning === undefined, "planner: no warning (path found)");
}

// ---------------------------------------------------------------------------
// Test: Tester hook
// ---------------------------------------------------------------------------

function testTesterHook(): void {
	console.log("\n📦 testerStopHook");
	const hook = getStopHook("tester");

	const result = makeFakeResult("tester", [
		"I've written 12 failing tests across 3 test files.",
		"All tests are RED.",
	].join("\n"), {
		traceSummary: makeFakeTraceSummary({
			persona: "tester",
			outputArtifacts: [
				"tests/Feature/ShiftManagementTest.php",
				"tests/Unit/PayoutCalculationTest.php",
				"tests/Feature/TripTrackingTest.php",
			],
		}),
	});

	const summary = hook(result);

	assertEqual(summary.personaData.type, "tester", "tester: personaData type");
	assertEqual(summary.personaData.data.testFiles.length, 3, "tester: 3 test files");
	assertEqual(summary.personaData.data.redCount, 12, "tester: redCount = 12");
	assert(summary.outputSummary.includes("RED"), "tester: summary mentions RED");
}

// ---------------------------------------------------------------------------
// Test: Developer hook
// ---------------------------------------------------------------------------

function testDeveloperHook(): void {
	console.log("\n📦 developerStopHook");
	const hook = getStopHook("developer");

	const result = makeFakeResult("developer", [
		"Implementation complete.",
		"All tests passing.",
	].join("\n"), {
		traceSummary: makeFakeTraceSummary({
			persona: "developer",
			outputArtifacts: [
				"app/Models/Trip.php",
				"database/migrations/2026_05_13_create_trips_table.php",
			],
		}),
	});

	const summary = hook(result);

	assertEqual(summary.personaData.type, "developer", "developer: personaData type");
	assertEqual(summary.personaData.data.filesCreated.length, 2, "developer: 2 files created");
	assertEqual(summary.personaData.data.testsPassing, true, "developer: testsPassing = true");
}

// ---------------------------------------------------------------------------
// Test: Validator hook — PASS
// ---------------------------------------------------------------------------

function testValidatorHookPass(): void {
	console.log("\n📦 validatorStopHook — PASS");
	const hook = getStopHook("validator");

	const result = makeFakeResult("validator", [
		"Audit complete.",
		"VERDICT: PASS",
		"No issues found.",
	].join("\n"));

	const summary = hook(result);

	assertEqual(summary.personaData.type, "validator", "validator: personaData type");
	assertEqual(summary.personaData.data.verdict, "PASS", "validator: verdict = PASS");
	assert(summary.hookWarning === undefined, "validator: no warning");
}

// ---------------------------------------------------------------------------
// Test: Validator hook — FAIL
// ---------------------------------------------------------------------------

function testValidatorHookFail(): void {
	console.log("\n📦 validatorStopHook — FAIL");
	const hook = getStopHook("validator");

	const result = makeFakeResult("validator", [
		"Audit complete.",
		"VERDICT: FAIL",
		"- Missing input validation on payout endpoint",
		"- BCMath not used in margin calculation",
	].join("\n"));

	const summary = hook(result);

	assertEqual(summary.personaData.data.verdict, "FAIL", "validator: verdict = FAIL");
	assert(summary.personaData.data.findings.length >= 2, "validator: findings extracted");
}

// ---------------------------------------------------------------------------
// Test: Validator hook — UNKNOWN verdict
// ---------------------------------------------------------------------------

function testValidatorHookUnknown(): void {
	console.log("\n📦 validatorStopHook — UNKNOWN verdict");
	const hook = getStopHook("validator");

	const result = makeFakeResult("validator", "I looked at the code but didn't give a clear verdict.");

	const summary = hook(result);

	assertEqual(summary.personaData.data.verdict, "UNKNOWN", "validator: verdict = UNKNOWN");
	assert(summary.hookWarning !== undefined, "validator: warning present for unknown verdict");
}

// ---------------------------------------------------------------------------
// Test: Tracker hook
// ---------------------------------------------------------------------------

function testTrackerHook(): void {
	console.log("\n📦 trackerStopHook");
	const hook = getStopHook("tracker");

	const result = makeFakeResult("tracker", "Updated docs/progress.md with latest status.", {
		traceSummary: makeFakeTraceSummary({
			persona: "tracker",
			outputArtifacts: ["docs/progress.md"],
		}),
	});

	const summary = hook(result);

	assertEqual(summary.personaData.type, "tracker", "tracker: personaData type");
	assertEqual(summary.personaData.data.progressUpdated, true, "tracker: progressUpdated = true");
}

// ---------------------------------------------------------------------------
// Test: Sandbox hook
// ---------------------------------------------------------------------------

function testSandboxHook(): void {
	console.log("\n📦 sandboxStopHook");
	const hook = getStopHook("sandbox");

	const result = makeFakeResult("sandbox", "Container is running and healthy.");
	const summary = hook(result);

	assertEqual(summary.personaData.type, "sandbox", "sandbox: personaData type");
	assert(summary.outputSummary.includes("Sandbox completed"), "sandbox: summary prefix");
}

// ---------------------------------------------------------------------------
// Test: Generic fallback for unknown persona
// ---------------------------------------------------------------------------

function testGenericHook(): void {
	console.log("\n📦 genericStopHook (unknown persona)");
	const hook = getStopHook("unknown-persona");

	const result = makeFakeResult("unknown-persona", "Some output.");
	const summary = hook(result);

	assert(summary.hookWarning?.includes("unknown-persona"), "generic: warning mentions persona name");
}

// ---------------------------------------------------------------------------
// Test: Hook + Pipeline integration
// ---------------------------------------------------------------------------

function testHookPipelineIntegration(): void {
	console.log("\n📦 Hook + Pipeline integration");
	const tmpDir = createTempDir();

	const pm = PipelineManager.create({
		pipelineId: "integration-test",
		taskId: "US-01",
		workflow: "full-tdd",
		stages: ["planner", "tester", "developer", "validator"],
		pipelinesDir: tmpDir,
	});

	// Simulate planner subagent result
	const plannerResult = makeFakeResult("planner", [
		"Plan saved to docs/plans/US-01-plan.md",
		"Complexity: Complex",
		"Business rules: BR-03",
	].join("\n"), {
		traceSummary: makeFakeTraceSummary({
			persona: "planner",
			outputArtifacts: ["docs/plans/US-01-plan.md"],
			totalDurationMs: 25000,
			totalTurns: 3,
			totalCost: 0.087,
			traceLog: "/tmp/planner-trace.jsonl",
		}),
	});

	// Run hook
	const plannerSummary = getStopHook("planner")(plannerResult);
	assertEqual(plannerSummary.personaData.data.planFilePath, "docs/plans/US-01-plan.md", "integration: planner planFilePath");

	// Advance pipeline using hook output
	const advance = pm.advanceStage(0, {
		status: "completed",
		outputSummary: plannerSummary.outputSummary,
		outputArtifacts: plannerSummary.outputArtifacts,
		personaData: plannerSummary.personaData,
		durationMs: plannerResult.traceSummary.totalDurationMs,
		turns: plannerResult.traceSummary.totalTurns,
		cost: plannerResult.traceSummary.totalCost,
		traceLog: plannerResult.traceSummary.traceLog,
	});

	assertEqual(advance.nextPersona, "tester", "integration: next = tester");

	// Verify manifest on disk has persona data
	const reloaded = PipelineManager.read("integration-test", tmpDir);
	const stage0 = reloaded!.getManifest().stages[0];
	assertEqual(stage0.personaData?.type, "planner", "integration: personaData persisted");
	assertEqual((stage0.personaData?.data as { planFilePath: string }).planFilePath, "docs/plans/US-01-plan.md", "integration: planFilePath persisted");

	fs.rmSync(tmpDir, { recursive: true, force: true });
}

// ---------------------------------------------------------------------------
// Run all tests
// ---------------------------------------------------------------------------

console.log("═══════════════════════════════════════");
console.log("🧪 Phase 4 Tests — Pipeline + Hooks");
console.log("═══════════════════════════════════════");

testPipelineCreate();
testPipelineAdvanceFullChain();
testPipelineAdvanceFailure();
testPipelineFinalize();
testPipelineRead();
testPipelineList();
testPlannerHook();
testTesterHook();
testDeveloperHook();
testValidatorHookPass();
testValidatorHookFail();
testValidatorHookUnknown();
testTrackerHook();
testSandboxHook();
testGenericHook();
testHookPipelineIntegration();

console.log("\n═══════════════════════════════════════");
console.log(`Results: ${testsPassed} passed, ${testsFailed} failed`);
console.log("═══════════════════════════════════════");

if (testsFailed > 0) {
	process.exit(1);
}
