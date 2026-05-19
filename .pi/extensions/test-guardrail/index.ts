/**
 * Test Guardrail Extension
 * 
 * Blocks write/edit operations to tests/ directory unless the agent
 * persona is "tester". This enforces TDD discipline by ensuring only
 * the Tester can write tests.
 * 
 * Placement: .pi/extensions/test-guardrail/index.ts
 * Auto-discovered by pi — no /reload needed after creation.
 */

import type { ExtensionAPI } from "@earendil-works/pi-coding-agent";
import { isToolCallEventType } from "@earendil-works/pi-coding-agent";
import * as path from "node:path";

/**
 * Detect if the current session is running as the "tester" persona.
 * 
 * We detect this by examining the system prompt for the tester-specific
 * archetype phrase "The Quality Sentinel" which appears in the tester
 * agent definition.
 */
function isTesterSession(systemPrompt: string): boolean {
	const testerIndicators = [
		"The Quality Sentinel",
		"tester",
		"🧪 The Tester",
	];
	
	// Check if any tester indicator appears in the system prompt
	const promptLower = systemPrompt.toLowerCase();
	return testerIndicators.some(indicator => 
		promptLower.includes(indicator.toLowerCase())
	);
}

/**
 * Check if a file path is within the tests/ directory.
 */
function isTestFile(filePath: string): boolean {
	const normalized = path.normalize(filePath);
	// Match tests/ or tests\ at the start or after /workspace
	return /^tests[\/\\]/.test(normalized) || /[\/\\]tests[\/\\]/.test(normalized);
}

/**
 * Determine which persona is currently running based on system prompt.
 * Returns "unknown" if we can't determine.
 */
function detectPersona(systemPrompt: string): string {
	const promptLower = systemPrompt.toLowerCase();
	
	const personas: Record<string, string[]> = {
		tester: ["the quality sentinel", "tester"],
		developer: ["the jailed craftsman", "developer"],
		planner: ["the rigorous architect", "planner"],
		validator: ["the gatekeeper of truth", "validator"],
		tracker: ["tracker"],
		sandbox: ["sandbox"],
	};
	
	for (const [persona, indicators] of Object.entries(personas)) {
		if (indicators.some(ind => promptLower.includes(ind))) {
			return persona;
		}
	}
	
	return "unknown";
}

export default function (pi: ExtensionAPI) {
	// -----------------------------------------------------------------------
	// Guardrail: Block write to tests/ unless agent is tester
	// -----------------------------------------------------------------------

	pi.on("tool_call", async (event, ctx) => {
		// Check write tool
		if (isToolCallEventType("write", event)) {
			const filePath = event.input.path as string;
			
			if (isTestFile(filePath)) {
				const systemPrompt = ctx.getSystemPrompt();
				const persona = detectPersona(systemPrompt);
				
				if (persona !== "tester") {
					return {
						block: true,
						reason: `🛡️ Test Guardrail: Only the Tester persona can write to the tests/ directory.\n\nCurrent persona: "${persona}"\nBlocked file: ${filePath}\n\nIf you need tests written, invoke the Tester via /test or the full TDD pipeline via /tdd.`,
					};
				}
				
				// Tester is writing tests — allow, but log
				console.log(`[test-guardrail] Tester writing: ${filePath}`);
			}
		}
		
		// Check edit tool (may be editing test files)
		if (isToolCallEventType("edit", event)) {
			const filePath = event.input.path as string;
			
			if (isTestFile(filePath)) {
				const systemPrompt = ctx.getSystemPrompt();
				const persona = detectPersona(systemPrompt);
				
				if (persona !== "tester") {
					return {
						block: true,
						reason: `🛡️ Test Guardrail: Only the Tester persona can edit test files.\n\nCurrent persona: "${persona}"\nBlocked file: ${filePath}\n\nIf you need test modifications, invoke the Tester via /test or escalate to the Planner.`,
					};
				}
				
				console.log(`[test-guardrail] Tester editing: ${filePath}`);
			}
		}
	});

	// -----------------------------------------------------------------------
	// Optional: Notify on session start about guardrail status
	// -----------------------------------------------------------------------

	pi.on("session_start", async (_event, ctx) => {
		const systemPrompt = ctx.getSystemPrompt();
		
		if (isTesterSession(systemPrompt)) {
			console.log("[test-guardrail] Tester session active — test writing ENABLED");
		} else {
			const persona = detectPersona(systemPrompt);
			if (persona !== "unknown") {
				console.log(`[test-guardrail] ${persona} session active — test writing BLOCKED`);
			}
		}
	});
}