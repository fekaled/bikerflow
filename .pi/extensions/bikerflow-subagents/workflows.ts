/**
 * Workflow Discovery — reads `.pi/workflows/*.md` with YAML frontmatter
 *
 * Parses workflow definitions that specify which personas participate
 * and in what order. Used by the orchestrator to build chain stages.
 *
 * Workflow file format:
 *   ---
 *   name: full-tdd
 *   description: Full TDD pipeline
 *   stages:
 *     - planner
 *     - tester
 *     - developer
 *     - validator
 *     - tracker
 *   ---
 *   # Body (optional documentation)
 */

import * as fs from "node:fs";
import * as path from "node:path";
import { parseFrontmatter } from "@mariozechner/pi-coding-agent";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface WorkflowDefinition {
	/** Unique workflow name (e.g. "full-tdd", "plan-only") */
	name: string;
	/** Human-readable description */
	description: string;
	/** Ordered list of persona names */
	stages: string[];
	/** Full file path */
	filePath: string;
}

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

function isDirectory(p: string): boolean {
	try {
		return fs.statSync(p).isDirectory();
	} catch {
		return false;
	}
}

/**
 * Walk up from `cwd` to find the nearest `.pi/workflows/` directory.
 */
function findWorkflowsDir(cwd: string): string | null {
	let currentDir = cwd;
	while (true) {
		const candidate = path.join(currentDir, ".pi", "workflows");
		if (isDirectory(candidate)) return candidate;

		const parentDir = path.dirname(currentDir);
		if (parentDir === currentDir) return null;
		currentDir = parentDir;
	}
}

function loadWorkflowsFromDir(dir: string): WorkflowDefinition[] {
	const workflows: WorkflowDefinition[] = [];

	let entries: fs.Dirent[];
	try {
		entries = fs.readdirSync(dir, { withFileTypes: true });
	} catch {
		return workflows;
	}

	for (const entry of entries) {
		if (!entry.name.endsWith(".md")) continue;
		if (!entry.isFile() && !entry.isSymbolicLink()) continue;

		const filePath = path.join(dir, entry.name);
		let content: string;
		try {
			content = fs.readFileSync(filePath, "utf-8");
		} catch {
			continue;
		}

		interface WorkflowFrontmatter {
			name?: string;
			description?: string;
			stages?: string[];
		}

		const { frontmatter } = parseFrontmatter<WorkflowFrontmatter>(content);

		// Skip files missing required frontmatter fields
		if (!frontmatter.name || !Array.isArray(frontmatter.stages) || frontmatter.stages.length === 0) {
			continue;
		}

		workflows.push({
			name: frontmatter.name,
			description: frontmatter.description ?? "",
			stages: frontmatter.stages.filter((s): s is string => typeof s === "string"),
			filePath,
		});
	}

	return workflows;
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Discover all workflow definitions from `.pi/workflows/*.md`.
 *
 * @param cwd - Current working directory (used to locate `.pi/workflows/`)
 * @returns Array of discovered workflow definitions
 */
export function discoverWorkflows(cwd: string): WorkflowDefinition[] {
	const workflowsDir = findWorkflowsDir(cwd);
	if (!workflowsDir) return [];
	return loadWorkflowsFromDir(workflowsDir);
}

/**
 * Find a single workflow by name. Returns `undefined` if not found.
 */
export function findWorkflow(cwd: string, name: string): WorkflowDefinition | undefined {
	return discoverWorkflows(cwd).find((w) => w.name === name);
}
