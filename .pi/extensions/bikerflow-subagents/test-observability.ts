/**
 * Test script for the observability module.
 *
 * Run: npx jiti .pi/extensions/bikerflow-subagents/test-observability.ts
 *
 * Exercises TraceLogger and LogSanitizer with fake JSON events
 * and verifies the JSONL output is well-formed and correct.
 */

import * as fs from "node:fs";
import * as os from "node:os";
import * as path from "node:path";
import { LogSanitizer, TraceLogger } from "./observability.js";

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
	return fs.mkdtempSync(path.join(os.tmpdir(), "bikerflow-test-obs-"));
}

function readTraceLines(filePath: string): Record<string, unknown>[] {
	const content = fs.readFileSync(filePath, "utf-8").trim();
	return content
		.split("\n")
		.filter(Boolean)
		.map((line) => JSON.parse(line));
}

// ---------------------------------------------------------------------------
// Test: LogSanitizer
// ---------------------------------------------------------------------------

function testSanitizer(): void {
	console.log("\n📦 LogSanitizer");
	const s = new LogSanitizer();

	// write tool — strips content, keeps path + length
	const writeContent = "<?php\n\nclass Shift {}\n";
	const writeArgs = s.sanitizeArgs("write", {
		path: "app/Models/Shift.php",
		content: writeContent,
		extra: true,
	});
	assertEqual(writeArgs.path, "app/Models/Shift.php", "write: keeps path");
	assertEqual(writeArgs.contentLength, writeContent.length, "write: keeps contentLength");
	assert(!("content" in (writeArgs as Record<string, unknown>)), "write: strips content field");
	assertEqual(writeArgs.extra, true, "write: keeps other fields");

	// edit tool — keeps path + lengths only
	const editArgs = s.sanitizeArgs("edit", {
		path: "routes/web.php",
		oldText: "Route::get('/old'",
		newText: "Route::get('/new'",
	});
	assertEqual(editArgs.path, "routes/web.php", "edit: keeps path");
	assertEqual(editArgs.oldTextLength, 17, "edit: oldTextLength");
	assertEqual(editArgs.newTextLength, 17, "edit: newTextLength");
	assert(!("oldText" in (editArgs as Record<string, unknown>)), "edit: strips oldText");

	// bash tool — args pass through
	const bashArgs = s.sanitizeArgs("bash", { command: "php artisan test" });
	assertEqual(bashArgs.command, "php artisan test", "bash: args pass through");

	// read tool — args pass through (sanitized on result side)
	const readArgs = s.sanitizeArgs("read", { path: "docs/prd.md", offset: 1, limit: 50 });
	assertEqual(readArgs.path, "docs/prd.md", "read: args pass through");

	// bash result — truncates long output
	const longOutput = "x".repeat(600);
	const bashResult = s.sanitizeResult("bash", { output: longOutput, exitCode: 0 });
	assert(
		(bashResult as Record<string, unknown>).output !== longOutput,
		"bash result: truncates long output",
	);
	assert(
		((bashResult as Record<string, unknown>).output as string).includes("[truncated]"),
		"bash result: includes [truncated] marker",
	);
	assertEqual((bashResult as Record<string, unknown>).exitCode, 0, "bash result: keeps exitCode");

	// read result — strips content, keeps metadata
	const readContent = "blah blah blah";
	const readResult = s.sanitizeResult("read", {
		path: "docs/prd.md",
		content: readContent,
		offset: 1,
		limit: 50,
		isError: false,
	});
	assert(!("content" in (readResult as Record<string, unknown>)), "read result: strips content");
	assertEqual((readResult as Record<string, unknown>).contentLength, readContent.length, "read result: keeps contentLength");
	assertEqual((readResult as Record<string, unknown>).path, "docs/prd.md", "read result: keeps path");
}

// ---------------------------------------------------------------------------
// Test: TraceLogger — full lifecycle
// ---------------------------------------------------------------------------

function testTraceLifecycle(): void {
	console.log("\n📦 TraceLogger — full lifecycle");
	const tmpDir = createTempDir();

	const logger = TraceLogger.start("planner", "US-01 trip tracking", "US-01", tmpDir);
	const tracePath = logger.traceLogPath;

	// Verify file was created
	assert(fs.existsSync(tracePath), "trace file created");
	assert(tracePath.includes("planner-US-01"), "trace filename includes persona and taskId");

	// Simulate a pi --mode json event stream
	// Turn 1: read + assistant message
	logger.onEvent({ type: "turn_start" });
	logger.onEvent({
		type: "message_start",
		message: { role: "assistant", content: [] },
	});
	logger.onEvent({
		type: "tool_execution_start",
		toolCallId: "tc-1",
		toolName: "read",
		args: { path: "docs/bikerflow-prd.md" },
	});
	logger.onEvent({
		type: "tool_execution_end",
		toolCallId: "tc-1",
		toolName: "read",
		result: { path: "docs/bikerflow-prd.md", content: "PRD content...", isError: false },
		isError: false,
	});
	logger.onEvent({
		type: "message_end",
		message: {
			role: "assistant",
			content: [{ type: "text", text: "I've read the PRD." }],
			usage: { input: 2500, output: 800, cacheRead: 0, cacheWrite: 0, cost: { total: 0.035 } },
			stopReason: "end_turn",
		},
	});
	logger.onEvent({ type: "turn_end", message: {}, toolResults: [] });

	// Turn 2: write a plan file
	logger.onEvent({ type: "turn_start" });
	logger.onEvent({
		type: "tool_execution_start",
		toolCallId: "tc-2",
		toolName: "write",
		args: { path: "docs/plans/US-01-trip-tracking.md", content: "# Plan\n..." },
	});
	logger.onEvent({
		type: "tool_execution_end",
		toolCallId: "tc-2",
		toolName: "write",
		result: { success: true },
		isError: false,
	});
	logger.onEvent({
		type: "message_end",
		message: {
			role: "assistant",
			content: [{ type: "text", text: "Plan saved to docs/plans/US-01-trip-tracking.md" }],
			usage: { input: 3000, output: 500, cacheRead: 2500, cacheWrite: 0, cost: { total: 0.02 } },
			stopReason: "end_turn",
		},
	});
	logger.onEvent({ type: "turn_end", message: {}, toolResults: [] });

	// Finalize
	const summary = logger.finalize(0, "end_turn", "Plan saved to docs/plans/US-01-trip-tracking.md");

	// Verify summary
	assertEqual(summary.persona, "planner", "summary: persona");
	assertEqual(summary.taskId, "US-01", "summary: taskId");
	assertEqual(summary.totalTurns, 2, "summary: totalTurns");
	assertEqual(summary.totalInputTokens, 5500, "summary: totalInputTokens");
	assertEqual(summary.totalOutputTokens, 1300, "summary: totalOutputTokens");
	assertEqual(summary.totalCacheReadTokens, 2500, "summary: totalCacheReadTokens");
	assertEqual(summary.exitCode, 0, "summary: exitCode");
	assertEqual(summary.stopReason, "end_turn", "summary: stopReason");
	assertEqual(summary.toolCalls, 2, "summary: toolCalls (read + write)");
	assertEqual(summary.toolCallsFailed, 0, "summary: toolCallsFailed");
	assert(summary.totalCost > 0, "summary: totalCost > 0");
	assert(summary.totalDurationMs >= 0, "summary: totalDurationMs >= 0");
	assert(
		summary.outputArtifacts.includes("docs/plans/US-01-trip-tracking.md"),
		"summary: outputArtifacts includes plan file",
	);

	// Verify JSONL contents
	const lines = readTraceLines(tracePath);
	assert(lines.length > 0, "trace file has content");

	const eventTypes = lines.map((l) => l.event);
	assert(eventTypes[0] === "agent_start", "first event is agent_start");
	assert(eventTypes[eventTypes.length - 1] === "agent_end", "last event is agent_end");
	assert(eventTypes.includes("turn_start"), "includes turn_start");
	assert(eventTypes.includes("turn_end"), "includes turn_end");
	assert(eventTypes.includes("message_start"), "includes message_start");
	assert(eventTypes.includes("message_end"), "includes message_end");
	assert(eventTypes.includes("tool_execution_start"), "includes tool_execution_start");
	assert(eventTypes.includes("tool_execution_end"), "includes tool_execution_end");

	// Verify all lines have timestamps
	const allHaveTs = lines.every((l) => typeof l.ts === "string" && l.ts.length > 0);
	assert(allHaveTs, "all events have timestamps");

	// Verify write tool args were sanitized
	const writeStartEvent = lines.find(
		(l) => l.event === "tool_execution_start" && l.tool === "write",
	);
	assert(
		!("content" in (writeStartEvent!.args as Record<string, unknown>)),
		"write args: content stripped in trace",
	);
	assert(
		typeof (writeStartEvent!.args as Record<string, unknown>).contentLength === "number",
		"write args: contentLength present",
	);

	// Verify read result was sanitized
	const readEndEvent = lines.find(
		(l) => l.event === "tool_execution_end" && l.tool === "read",
	);
	assert(
		!("content" in ((readEndEvent!.resultSummary as Record<string, unknown>) ?? {})),
		"read result: content stripped in trace",
	);

	// Cleanup
	fs.rmSync(tmpDir, { recursive: true, force: true });
}

// ---------------------------------------------------------------------------
// Test: TraceLogger — error and failure handling
// ---------------------------------------------------------------------------

function testErrorHandling(): void {
	console.log("\n📦 TraceLogger — error handling");
	const tmpDir = createTempDir();

	const logger = TraceLogger.start("developer", "US-01 implement", "US-01", tmpDir);

	// Simulate a tool failure
	logger.onEvent({ type: "turn_start" });
	logger.onEvent({
		type: "tool_execution_start",
		toolCallId: "tc-err",
		toolName: "bash",
		args: { command: "php artisan migrate" },
	});
	logger.onEvent({
		type: "tool_execution_end",
		toolCallId: "tc-err",
		toolName: "bash",
		result: { output: "SQLSTATE[42S01]: Table already exists", exitCode: 1 },
		isError: true,
	});
	logger.onEvent({ type: "turn_end", message: {}, toolResults: [] });

	// Finalize with non-zero exit
	const summary = logger.finalize(1, "error", "Migration failed");

	assertEqual(summary.exitCode, 1, "error: exitCode = 1");
	assertEqual(summary.stopReason, "error", "error: stopReason = error");
	assertEqual(summary.toolCallsFailed, 1, "error: toolCallsFailed = 1");
	assertEqual(summary.totalTurns, 1, "error: totalTurns = 1");

	// Verify JSONL has tool failure recorded
	const lines = readTraceLines(logger.traceLogPath);
	const toolEnd = lines.find(
		(l) => l.event === "tool_execution_end" && l.toolCallId === "tc-err",
	);
	assertEqual(toolEnd!.success, false, "error: tool end event has success=false");

	fs.rmSync(tmpDir, { recursive: true, force: true });
}

// ---------------------------------------------------------------------------
// Test: TraceLogger — edit tool tracking
// ---------------------------------------------------------------------------

function testEditTracking(): void {
	console.log("\n📦 TraceLogger — edit tool artifact tracking");
	const tmpDir = createTempDir();

	const logger = TraceLogger.start("developer", "fix migration", "HOTFIX-1", tmpDir);

	logger.onEvent({ type: "turn_start" });
	logger.onEvent({
		type: "tool_execution_start",
		toolCallId: "tc-edit-1",
		toolName: "edit",
		args: { path: "app/Models/Shift.php", oldText: "foo", newText: "bar" },
	});
	logger.onEvent({
		type: "tool_execution_end",
		toolCallId: "tc-edit-1",
		toolName: "edit",
		result: {},
		isError: false,
	});
	logger.onEvent({ type: "turn_end", message: {}, toolResults: [] });

	const summary = logger.finalize(0, "end_turn", "Fixed");

	assert(
		summary.outputArtifacts.includes("app/Models/Shift.php"),
		"edit: path tracked as artifact",
	);

	// Verify edit args were sanitized
	const lines = readTraceLines(logger.traceLogPath);
	const editStart = lines.find(
		(l) => l.event === "tool_execution_start" && l.tool === "edit",
	);
	assert(
		!("oldText" in (editStart!.args as Record<string, unknown>)),
		"edit args: oldText stripped",
	);
	assertEqual((editStart!.args as Record<string, unknown>).oldTextLength, 3, "edit args: oldTextLength");
	assertEqual((editStart!.args as Record<string, unknown>).newTextLength, 3, "edit args: newTextLength");

	fs.rmSync(tmpDir, { recursive: true, force: true });
}

// ---------------------------------------------------------------------------
// Test: TraceLogger — deduplication of artifacts
// ---------------------------------------------------------------------------

function testArtifactDeduplication(): void {
	console.log("\n📦 TraceLogger — artifact deduplication");
	const tmpDir = createTempDir();

	const logger = TraceLogger.start("developer", "US-01", "US-01", tmpDir);

	logger.onEvent({ type: "turn_start" });

	// Write the same file twice (should only appear once in artifacts)
	logger.onEvent({
		type: "tool_execution_start",
		toolCallId: "tc-w1",
		toolName: "write",
		args: { path: "app/Models/Shift.php", content: "v1" },
	});
	logger.onEvent({ type: "tool_execution_end", toolCallId: "tc-w1", toolName: "write", result: {}, isError: false });

	logger.onEvent({
		type: "tool_execution_start",
		toolCallId: "tc-w2",
		toolName: "write",
		args: { path: "app/Models/Shift.php", content: "v2" },
	});
	logger.onEvent({ type: "tool_execution_end", toolCallId: "tc-w2", toolName: "write", result: {}, isError: false });

	logger.onEvent({ type: "turn_end", message: {}, toolResults: [] });

	const summary = logger.finalize(0, "end_turn", "Done");
	assertEqual(summary.outputArtifacts.length, 1, "dedup: only one artifact for same path");
	assertEqual(summary.outputArtifacts[0], "app/Models/Shift.php", "dedup: correct path");

	fs.rmSync(tmpDir, { recursive: true, force: true });
}

// ---------------------------------------------------------------------------
// Test: message_update frequency
// ---------------------------------------------------------------------------

function testMessageUpdate(): void {
	console.log("\n📦 TraceLogger — message_update handling");
	const tmpDir = createTempDir();

	const logger = TraceLogger.start("tester", "test run", "TEST-1", tmpDir);

	logger.onEvent({ type: "turn_start" });
	logger.onEvent({
		type: "message_update",
		message: { role: "assistant" },
		assistantMessageEvent: { type: "text_delta", delta: "Hello" },
	});
	logger.onEvent({
		type: "message_update",
		message: { role: "assistant" },
		assistantMessageEvent: { type: "text_delta", delta: " world" },
	});
	logger.onEvent({
		type: "message_end",
		message: {
			role: "assistant",
			content: [{ type: "text", text: "Hello world" }],
			usage: { input: 100, output: 10, cacheRead: 0, cacheWrite: 0, cost: { total: 0.001 } },
		},
	});
	logger.onEvent({ type: "turn_end", message: {}, toolResults: [] });

	const summary = logger.finalize(0, "end_turn", "Hello world");

	const lines = readTraceLines(logger.traceLogPath);
	const updates = lines.filter((l) => l.event === "message_update");
	assertEqual(updates.length, 2, "message_update: logged 2 delta events");
	assertEqual(updates[0].deltaType, "text_delta", "message_update: captures deltaType");

	fs.rmSync(tmpDir, { recursive: true, force: true });
}

// ---------------------------------------------------------------------------
// Run all tests
// ---------------------------------------------------------------------------

console.log("═══════════════════════════════════════");
console.log("🧪 Observability Module Tests");
console.log("═══════════════════════════════════════");

testSanitizer();
testTraceLifecycle();
testErrorHandling();
testEditTracking();
testArtifactDeduplication();
testMessageUpdate();

console.log("\n═══════════════════════════════════════");
console.log(`Results: ${testsPassed} passed, ${testsFailed} failed`);
console.log("═══════════════════════════════════════");

if (testsFailed > 0) {
	process.exit(1);
}
