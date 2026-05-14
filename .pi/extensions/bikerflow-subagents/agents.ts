/**
 * Agent Discovery — reads `.pi/agents/*.md` with YAML frontmatter
 *
 * Scans project-local agents, parses frontmatter for name/description/tools/model,
 * and returns typed AgentConfig objects for use by subagent.ts.
 *
 * Uses pi's built-in `parseFrontmatter` and `getAgentDir` utilities.
 */

import * as fs from "node:fs";
import * as path from "node:path";
import { parseFrontmatter } from "@mariozechner/pi-coding-agent";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface AgentConfig {
	name: string;
	description: string;
	tools?: string[];
	model?: string;
	systemPrompt: string;
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
 * Walk up from `cwd` to find the nearest `.pi/agents/` directory.
 */
function findProjectAgentsDir(cwd: string): string | null {
	let currentDir = cwd;
	while (true) {
		const candidate = path.join(currentDir, ".pi", "agents");
		if (isDirectory(candidate)) return candidate;

		const parentDir = path.dirname(currentDir);
		if (parentDir === currentDir) return null;
		currentDir = parentDir;
	}
}

function loadAgentsFromDir(dir: string): AgentConfig[] {
	const agents: AgentConfig[] = [];

	let entries: fs.Dirent[];
	try {
		entries = fs.readdirSync(dir, { withFileTypes: true });
	} catch {
		return agents;
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

		const { frontmatter, body } = parseFrontmatter<Record<string, string>>(content);

		// Skip files missing required frontmatter fields
		if (!frontmatter.name || !frontmatter.description) {
			continue;
		}

		const tools = frontmatter.tools
			?.split(",")
			.map((t: string) => t.trim())
			.filter(Boolean);

		agents.push({
			name: frontmatter.name,
			description: frontmatter.description,
			tools: tools && tools.length > 0 ? tools : undefined,
			model: frontmatter.model,
			systemPrompt: body,
			filePath,
		});
	}

	return agents;
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Discover all project-local BikerFlow agents from `.pi/agents/*.md`.
 *
 * @param cwd - Current working directory (used to locate `.pi/agents/`)
 * @returns Array of discovered agent configurations
 */
export function discoverAgents(cwd: string): AgentConfig[] {
	const agentsDir = findProjectAgentsDir(cwd);
	if (!agentsDir) return [];
	return loadAgentsFromDir(agentsDir);
}

/**
 * Find a single agent by name. Returns `undefined` if not found.
 */
export function findAgent(cwd: string, name: string): AgentConfig | undefined {
	return discoverAgents(cwd).find((a) => a.name === name);
}
