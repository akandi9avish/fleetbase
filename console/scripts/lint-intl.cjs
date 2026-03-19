#!/usr/bin/env node

const fs = require("fs");
const path = require("path");
const yaml = require("js-yaml");

function parseArgs(argv) {
    const options = {
        silent: false,
        appPath: "./app",
        translationPath: "./translations/en-us.yaml",
    };

    for (let i = 0; i < argv.length; i++) {
        const arg = argv[i];
        if (arg === "--silent" || arg === "-s") {
            options.silent = true;
            continue;
        }

        if (arg === "--path" || arg === "-p") {
            options.appPath = argv[i + 1] ?? options.appPath;
            i += 1;
            continue;
        }

        if (arg === "--translation-path") {
            options.translationPath = argv[i + 1] ?? options.translationPath;
            i += 1;
            continue;
        }
    }

    return options;
}

function findTranslationKeys(filePath) {
    const content = fs.readFileSync(filePath, "utf8");

    if (filePath.endsWith(".hbs")) {
        const hbsRegex = /\{\{\s*t\s+["'`]([^"'`]+)["'`]\s*}}|\(t\s+["'`]([^"'`]+)["'`]\)/g;
        return [...content.matchAll(hbsRegex)].map((match) => match[1] || match[2]).filter(Boolean);
    }

    if (filePath.endsWith(".js")) {
        const jsRegex = /this\.intl\.t\s*\(\s*["'`]([^"'`]+)["'`]\s*(?:,\s*\{.*?\}\s*)?\)/g;
        return [...content.matchAll(jsRegex)].map((match) => match[1]).filter(Boolean);
    }

    return [];
}

function hasNestedKey(tree, dottedKey) {
    return dottedKey.split(".").every((part) => {
        if (!tree || !Object.prototype.hasOwnProperty.call(tree, part)) {
            return false;
        }

        tree = tree[part];
        return true;
    });
}

function walkFiles(directory) {
    const entries = fs.readdirSync(directory, { withFileTypes: true });
    const files = [];

    for (const entry of entries) {
        const fullPath = path.join(directory, entry.name);
        if (entry.isDirectory()) {
            files.push(...walkFiles(fullPath));
            continue;
        }

        if (fullPath.endsWith(".js") || fullPath.endsWith(".hbs")) {
            files.push(fullPath);
        }
    }

    return files;
}

function main() {
    const args = parseArgs(process.argv.slice(2));
    const cwd = process.cwd();
    const appPath = path.resolve(cwd, args.appPath);
    const translationPath = path.resolve(cwd, args.translationPath);

    if (!fs.existsSync(appPath)) {
        console.error(`[intl-lint] App path not found: ${appPath}`);
        process.exit(1);
    }

    if (!fs.existsSync(translationPath)) {
        console.error(`[intl-lint] Translation file not found: ${translationPath}`);
        process.exit(1);
    }

    const translationTree = yaml.load(fs.readFileSync(translationPath, "utf8")) || {};
    const files = walkFiles(appPath);
    const missingByFile = new Map();

    for (const filePath of files) {
        const keys = findTranslationKeys(filePath);
        if (keys.length === 0) {
            continue;
        }

        const missing = keys.filter((key) => !hasNestedKey(translationTree, key));
        if (missing.length > 0) {
            missingByFile.set(filePath, missing);
        }
    }

    if (missingByFile.size === 0) {
        console.log("[intl-lint] Translation key check passed.");
        return;
    }

    for (const [filePath, missingKeys] of missingByFile.entries()) {
        console.error(`[intl-lint] Missing keys in ${path.relative(cwd, filePath)}:`);
        for (const key of missingKeys) {
            console.error(`  - ${key}`);
        }
    }

    if (args.silent) {
        console.warn("[intl-lint] Missing keys detected but tolerated due to --silent.");
        return;
    }

    process.exit(1);
}

main();
