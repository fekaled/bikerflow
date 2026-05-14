/**
 * Per-Persona Stop Hooks
 *
 * Extracts structured StageSummary data from SubagentResult after a
 * subagent process exits. Each persona has a dedicated hook that:
 *   - Reads finalOutput and traceSummary.outputArtifacts
 *   - Parses persona-specific semantics via lightweight regex
 *   - Returns a StageSummary for the pipeline manifest
 *
 * Extraction strategy (Option D):
 *   - File artifacts: from traceSummary.outputArtifacts (write/edit tool calls)
 *   - Semantic fields: lightweight regex on finalOutput text
 */

import type { SubagentResult } from "./subagent.js";

// ---------------------------------------------------------------------------
// Persona data types
// ---------------------------------------------------------------------------

export interface PlannerData {
	planFilePath?: string;
	businessRules?: string[];
	complexity?: "Simple" | "Medium" | "Complex";
}

export interface TesterData {
	testFiles: string[];
	redCount?: number;
	totalTests?: number;
}

export interface DeveloperData {
	filesCreated: string[];
	filesModified: string[];
	testsPassing?: boolean;
}

export interface ValidatorData {
	verdict: "PASS" | "FAIL" | "UNKNOWN";
	findings: string[];
}

export interface TrackerData {
	progressUpdated: boolean;
}

export interface SandboxData {
	containerStatus?: string;
}

/**
 * Discriminated union of persona-specific data.
 * The `type` field identifies which `data` shape is present.
 */
export type PersonaData =
	| { type: "planner"; data: PlannerData }
	| { type: "tester"; data: TesterData }
	| { type: "developer"; data: DeveloperData }
	| { type: "validator"; data: ValidatorData }
	| { type: "tracker"; data: TrackerData }
	| { type: "sandbox"; data: SandboxData };

/** Structured output from a stop hook. */
export interface StageSummary {
	/** Human-readable summary of what the persona produced. */
	outputSummary: string;
	/** Files created or modified by the persona. */
	outputArtifacts: string[];
	/** Typed persona-specific data. */
	personaData: PersonaData;
	/** Non-fatal warning if expected data could not be extracted. */
	hookWarning?: string;
}

// ---------------------------------------------------------------------------
// Hook type & registry
// ---------------------------------------------------------------------------

type StopHook = (result: SubagentResult) => StageSummary;

const hookRegistry: Record<string, StopHook> = {
	planner: plannerStopHook,
	tester: testerStopHook,
	developer: developerStopHook,
	validator: validatorStopHook,
	tracker: trackerStopHook,
	sandbox: sandboxStopHook,
};

/**
 * Get the stop hook for a given persona name.
 * Returns a generic fallback hook for unknown personas.
 */
export function getStopHook(persona: string): StopHook {
	return hookRegistry[persona] ?? genericStopHook;
}

// ---------------------------------------------------------------------------
// Planner hook
// ---------------------------------------------------------------------------

function plannerStopHook(result: SubagentResult): StageSummary {
	const output = result.finalOutput;
	const artifacts = result.traceSummary.outputArtifacts;

	// --- Plan file path ---
	let planFilePath: string | undefined;

	// From artifacts: look for docs/plans/ paths
	const planArtifact = artifacts.find(
		(a) => a.includes("docs/plans/") || a.includes("docs\\plans\\"),
	);
	if (planArtifact) planFilePath = planArtifact;

	// From text: explicit "saved to" / "created at" / "written to" mention
	if (!planFilePath) {
		const match = output.match(
			/(?:plan\s+(?:saved|written|output|created)\s+(?:to|at)|saved\s+(?:plan\s+)?(?:to|at)|created\s+at|written\s+to|output\s+(?:saved|written)\s+to)[:\s]*[`'"]?(docs[/\\]plans[/\\][\w.-]+\.md)[`'"]?/i,
		);
		if (match) planFilePath = match[1];
	}

	// Broader catch: any docs/plans/*.md path in the output
	if (!planFilePath) {
		const pathMatch = output.match(/(?:^|\s|["'`()])(docs[/\\]plans[/\\][\w.-]+\.md)/m);
		if (pathMatch) planFilePath = pathMatch[1];
	}

	// --- Business rules ---
	const businessRules: string[] = [];

	// "Business rules: BR-01, BR-03, BR-05"
	const brListMatch = output.match(/business\s+rules?[:\s]+([\w\s,-]+)/i);
	if (brListMatch) {
		const listed = brListMatch[1]
			.split(/[,;]/)
			.map((r) => r.trim())
			.filter(Boolean);
		businessRules.push(...listed);
	}

	// Also collect any BR-XX mentions in the full text
	for (const m of output.matchAll(/\b(BR-\d{2})\b/g)) {
		if (!businessRules.includes(m[1])) businessRules.push(m[1]);
	}

	// --- Complexity ---
	let complexity: "Simple" | "Medium" | "Complex" | undefined;
	const complexMatch = output.match(/complexity[:\s]+(simple|medium|complex)/i);
	if (complexMatch) {
		complexity =
			(complexMatch[1].charAt(0).toUpperCase() + complexMatch[1].slice(1).toLowerCase()) as
				| "Simple"
				| "Medium"
				| "Complex";
	}

	// --- Warnings ---
	const warnings: string[] = [];
	if (!planFilePath) warnings.push("Could not extract plan file path");

	// --- Summary ---
	let summary = "Planner completed.";
	if (planFilePath) summary += ` Plan: ${planFilePath}`;
	if (businessRules.length > 0) summary += ` Rules: ${businessRules.join(", ")}`;
	if (complexity) summary += ` Complexity: ${complexity}`;

	return {
		outputSummary: summary,
		outputArtifacts: artifacts,
		personaData: {
			type: "planner",
			data: {
				planFilePath,
				businessRules: businessRules.length > 0 ? businessRules : undefined,
				complexity,
			},
		},
		hookWarning: warnings.length > 0 ? warnings.join("; ") : undefined,
	};
}

// ---------------------------------------------------------------------------
// Tester hook
// ---------------------------------------------------------------------------

function testerStopHook(result: SubagentResult): StageSummary {
	const output = result.finalOutput;
	const artifacts = result.traceSummary.outputArtifacts;

	// --- Test files ---
	const testFiles = artifacts.filter(
		(a) =>
			a.includes("tests/") ||
			a.includes("tests\\") ||
			a.includes("Test.php") ||
			a.includes("test_"),
	);

	// --- Test counts ---
	let redCount: number | undefined;
	let totalTests: number | undefined;

	// "12 failing tests" / "12 tests RED" / "12 failing"
	const redMatch = output.match(/(\d+)\s+(?:failing|red|failing\s+tests?)/i);
	if (redMatch) redCount = parseInt(redMatch[1], 10);

	// "12 tests written" / "12 test cases" / "12 tests"
	const totalMatch = output.match(/(\d+)\s+tests?(?:\s+(?:written|cases|total|across))?/i);
	if (totalMatch) totalTests = parseInt(totalMatch[1], 10);

	// --- Warnings ---
	const warnings: string[] = [];
	if (testFiles.length === 0) warnings.push("No test files found in artifacts");

	// --- Summary ---
	let summary = "Tester completed.";
	if (testFiles.length > 0) summary += ` ${testFiles.length} test file(s).`;
	if (redCount !== undefined) summary += ` ${redCount} tests RED.`;

	return {
		outputSummary: summary,
		outputArtifacts: artifacts,
		personaData: {
			type: "tester",
			data: { testFiles, redCount, totalTests },
		},
		hookWarning: warnings.length > 0 ? warnings.join("; ") : undefined,
	};
}

// ---------------------------------------------------------------------------
// Developer hook
// ---------------------------------------------------------------------------

function developerStopHook(result: SubagentResult): StageSummary {
	const output = result.finalOutput;
	const artifacts = result.traceSummary.outputArtifacts;

	// --- Files created vs modified ---
	// TraceLogger tracks both write and edit tool calls in outputArtifacts.
	// We can't perfectly distinguish, but we can heuristically split:
	//   - write tool paths → filesCreated
	//   - edit tool paths  → filesModified
	// Since outputArtifacts doesn't carry the tool name, we report all as
	// filesCreated and leave filesModified empty. A future iteration could
	// pass richer data from the trace log.
	const filesCreated = artifacts;
	const filesModified: string[] = [];

	// --- Tests passing? ---
	let testsPassing: boolean | undefined;
	if (/\btests?\s+(?:all\s+)?(?:passing|pass|green|passed)\b/i.test(output)) {
		testsPassing = true;
	} else if (/\btests?\s+(?:failing|fail|red|failed|still\s+failing)\b/i.test(output)) {
		testsPassing = false;
	}

	// --- Summary ---
	let summary = "Developer completed.";
	if (artifacts.length > 0) summary += ` ${artifacts.length} file(s) affected.`;
	if (testsPassing === true) summary += " All tests passing.";
	else if (testsPassing === false) summary += " Some tests still failing.";

	return {
		outputSummary: summary,
		outputArtifacts: artifacts,
		personaData: {
			type: "developer",
			data: { filesCreated, filesModified, testsPassing },
		},
	};
}

// ---------------------------------------------------------------------------
// Validator hook
// ---------------------------------------------------------------------------

function validatorStopHook(result: SubagentResult): StageSummary {
	const output = result.finalOutput;
	const artifacts = result.traceSummary.outputArtifacts;

	// --- Verdict ---
	let verdict: "PASS" | "FAIL" | "UNKNOWN" = "UNKNOWN";

	// Explicit FAIL patterns (check first — negative takes priority)
	if (
		/\b(?:VERDICT|AUDIT|RESULT|OUTCOME|CONCLUSION)[:\s]+FAIL(?:ED|URE)?\b/i.test(output) ||
		/\bREJECTED?\b/i.test(output) ||
		/\bBLOCKED\b/i.test(output) ||
		/\bdoes\s+not\s+(?:pass|meet|satisfy)\b/i.test(output) ||
		/\b❌\s*(?:fail|rejected|blocked)\b/i.test(output) ||
		/\bfailing\s+(?:validation|audit|check)\b/i.test(output)
	) {
		verdict = "FAIL";
	}
	// Explicit PASS patterns
	else if (
		/\b(?:VERDICT|AUDIT|RESULT|OUTCOME|CONCLUSION)[:\s]+PASS(?:ING|ED)?\b/i.test(output) ||
		/\bAPPROVED?\b/i.test(output) ||
		/\b✅\s*(?:PASS|APPROVED?)\b/i.test(output) ||
		/\bimplementation\s+(?:passes|is\s+approved|meets\s+all)\b/i.test(output) ||
		/\b(?:all\s+)?(?:checks?|criteria|rules|requirements)\s+(?:are\s+)?(?:met|passing|satisfied)\b/i.test(output) ||
		/\bno\s+(?:issues?|violations?|problems?|errors?|findings?)\s+(?:found|detected|identified)\b/i.test(output)
	) {
		verdict = "PASS";
	}
	// Heuristic: contains "pass" without "fail" → lean toward PASS
	else if (
		/\bpass(?:es|ed|ing)?\b/i.test(output) &&
		!/\bfail(?:ed|s|ing|ure)?\b/i.test(output)
	) {
		verdict = "PASS";
	}

	// --- Findings ---
	// Collect short bullet/numbered items that look like findings
	const findings: string[] = [];
	const lines = output.split("\n");
	for (const line of lines) {
		const trimmed = line.trim();
		const bulletMatch = trimmed.match(/^[-•*]\s+(.+)$/);
		const numberedMatch = trimmed.match(/^\d+\.\s+(.+)$/);
		const text = bulletMatch?.[1] ?? numberedMatch?.[1];
		if (
			text &&
			text.length > 5 &&
			text.length < 200 &&
			!findings.includes(text)
		) {
			findings.push(text);
		}
	}

	// --- Warnings ---
	const warnings: string[] = [];
	if (verdict === "UNKNOWN") warnings.push("Could not extract verdict from output");

	// --- Summary ---
	let summary = "Validator completed.";
	summary += ` Verdict: ${verdict}.`;
	if (findings.length > 0) summary += ` ${findings.length} finding(s).`;

	return {
		outputSummary: summary,
		outputArtifacts: artifacts,
		personaData: {
			type: "validator",
			data: { verdict, findings },
		},
		hookWarning: warnings.length > 0 ? warnings.join("; ") : undefined,
	};
}

// ---------------------------------------------------------------------------
// Tracker hook
// ---------------------------------------------------------------------------

function trackerStopHook(result: SubagentResult): StageSummary {
	const output = result.finalOutput;
	const artifacts = result.traceSummary.outputArtifacts;

	// --- Progress updated? ---
	const progressUpdated =
		/progress\.md\s+(?:updated|modified|written|saved)/i.test(output) ||
		artifacts.some((a) => a.includes("progress.md"));

	// --- Summary ---
	let summary = "Tracker completed.";
	if (progressUpdated) summary += " Progress board updated.";

	return {
		outputSummary: summary,
		outputArtifacts: artifacts,
		personaData: {
			type: "tracker",
			data: { progressUpdated },
		},
	};
}

// ---------------------------------------------------------------------------
// Sandbox hook
// ---------------------------------------------------------------------------

function sandboxStopHook(result: SubagentResult): StageSummary {
	const output = result.finalOutput;
	const artifacts = result.traceSummary.outputArtifacts;

	// --- Container status ---
	let containerStatus: string | undefined;
	const statusMatch = output.match(/container\s+(?:is\s+)?(?:status[:\s]+)?(\w+)/i);
	if (statusMatch) containerStatus = statusMatch[1];

	return {
		outputSummary: `Sandbox completed. ${truncate(output, 100)}`,
		outputArtifacts: artifacts,
		personaData: {
			type: "sandbox",
			data: { containerStatus },
		},
	};
}

// ---------------------------------------------------------------------------
// Generic fallback (for unknown personas)
// ---------------------------------------------------------------------------

function genericStopHook(result: SubagentResult): StageSummary {
	return {
		outputSummary: `${result.persona} completed. ${truncate(result.finalOutput, 100)}`,
		outputArtifacts: result.traceSummary.outputArtifacts,
		personaData: {
			type: "sandbox",
			data: {},
		},
		hookWarning: `No specific hook for persona "${result.persona}"`,
	};
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function truncate(str: string, maxLen: number): string {
	if (str.length <= maxLen) return str;
	return str.slice(0, maxLen - 3) + "...";
}
