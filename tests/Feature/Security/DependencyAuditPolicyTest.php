<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The dependency-audit policy, asserted as a contract rather than as text.
 *
 * A security gate is only a gate while its thresholds hold, and the edits that
 * dissolve one are all small: `moderate` to `high`, a dropped
 * `--abandoned=fail`, a `|| true` on the end of a line, a `continue-on-error`,
 * an advisory added to an ignore list. Every one of them leaves a workflow that
 * still passes and no longer protects anything. So the policy is written down
 * twice: once where it runs, and once here, where changing it has to be
 * deliberate.
 *
 * Two things make these assertions worth having rather than decorative.
 *
 * The commands are compared **exactly**, after collapsing whitespace, not by
 * looking for fragments. `npm audit --omit=dev --audit-level=moderate` passes;
 * the same line with `|| true`, an extra `--omit`, an ignore flag or a
 * different threshold does not.
 *
 * And the workflow is read with its comments stripped, so a directive can never
 * be satisfied by a line that only mentions it. A comment cannot weaken a gate,
 * and it must not be able to appear to strengthen one either.
 *
 * What is not asserted is the shape of the file: no line order, no indentation,
 * no wording. Nothing here parses YAML — adding a parser dependency in order to
 * test a dependency-security gate would be its own small joke — and GitHub
 * remains the authority on whether the workflow is valid.
 */
class DependencyAuditPolicyTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/dependency-security.yml';

    // --- The commands a developer and CI both run --------------------------

    /**
     * The npm thresholds are deliberately different, and the difference is the
     * policy: production dependencies reach a browser or a request, so they
     * block on moderate; the development tree only has to build and test, so it
     * blocks on high.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function manifestCommands(): array
    {
        return [
            'the locked PHP tree' => [
                'composer.json',
                'security',
                '@composer audit --locked --abandoned=fail',
            ],
            'npm production dependencies, moderate and above' => [
                'package.json',
                'audit:production',
                'npm audit --omit=dev --audit-level=moderate',
            ],
            'the full npm tree, high and above' => [
                'package.json',
                'audit:all',
                'npm audit --audit-level=high',
            ],
            'one command for both npm audits' => [
                'package.json',
                'security',
                'npm run audit:production && npm run audit:all',
            ],
        ];
    }

    /**
     * Exact, so that anything appended, inserted or retuned fails here rather
     * than passing because the original words are still present somewhere in
     * the line.
     */
    #[Test]
    #[DataProvider('manifestCommands')]
    public function the_manifests_declare_the_audit_commands_exactly(
        string $manifest,
        string $script,
        string $expected,
    ): void {
        $scripts = $this->scriptsFrom($manifest);

        $this->assertArrayHasKey($script, $scripts, "{$manifest} has no [{$script}] script.");
        $this->assertSame($expected, $this->normalize($scripts[$script]));
    }

    /**
     * A Composer script named after the command it calls would call itself.
     */
    #[Test]
    public function the_composer_security_script_is_not_recursive(): void
    {
        $this->assertArrayNotHasKey('audit', $this->scriptsFrom('composer.json'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forbiddenAuditFlags(): array
    {
        return [
            'a severity floor' => ['--ignore-severity'],
            'a reachability exemption' => ['--ignore-unreachable'],
            // The audit would then judge a smaller tree than the one that is
            // installed and tested with.
            'a narrowed tree' => ['--no-dev'],
        ];
    }

    #[Test]
    #[DataProvider('forbiddenAuditFlags')]
    public function the_composer_security_script_suppresses_nothing(string $flag): void
    {
        $this->assertStringNotContainsString($flag, $this->scriptsFrom('composer.json')['security'] ?? '');
    }

    /**
     * Composer will read an ignore list out of the manifest, which would
     * silence an advisory everywhere at once — locally, in CI, and in any
     * future release check — with nothing on the command line to show for it.
     */
    #[Test]
    public function composer_json_carries_no_advisory_ignore_list(): void
    {
        $manifest = $this->manifest('composer.json');
        $audit = $manifest['config']['audit'] ?? [];

        $this->assertIsArray($audit);
        $this->assertArrayNotHasKey(
            'ignore',
            $audit,
            'composer.json ignores an advisory. There are no exceptions today (docs/09-runbooks.md, section 8b).',
        );
    }

    // --- The workflow ------------------------------------------------------

    #[Test]
    public function the_dependency_security_workflow_exists(): void
    {
        $this->assertFileExists(base_path(self::WORKFLOW));
        $this->assertNotSame('', trim($this->workflow()));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredTriggers(): array
    {
        return [
            'pull requests' => ['pull_request:'],
            'pushes to master' => ['branches: [master]'],
            // A new advisory is published against code that has not changed, so
            // a workflow that only runs on a push would never see it.
            'a weekly schedule' => ['schedule:'],
            'a manual run' => ['workflow_dispatch:'],
        ];
    }

    #[Test]
    #[DataProvider('requiredTriggers')]
    public function the_workflow_runs_on_every_required_event(string $directive): void
    {
        $this->assertWorkflowDeclares($directive);
    }

    #[Test]
    public function the_schedule_is_a_cron_expression(): void
    {
        $this->assertMatchesRegularExpression(
            "/cron:\s*'[-\d\*\/, ]+'/",
            $this->workflow(),
            'The schedule declares no cron expression.',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredAudits(): array
    {
        return [
            'the lock file against the manifest' => ['composer validate --strict'],
            'the locked PHP tree' => ['composer security'],
            'npm production dependencies' => ['npm run audit:production'],
            'the full npm tree' => ['npm run audit:all'],
        ];
    }

    /**
     * Each audit is a `run:` command of its own, matched exactly.
     *
     * The workflow runs the same named commands a developer runs, so a local
     * pass and a CI pass mean the same thing. Exactness is what stops the
     * command being neutralised in place: `npm run audit:all || true` still
     * contains the words and would satisfy a substring check.
     */
    #[Test]
    #[DataProvider('requiredAudits')]
    public function the_workflow_runs_every_audit_as_a_command_of_its_own(string $command): void
    {
        $this->assertContains(
            $command,
            $this->workflowRunCommands(),
            "[{$command}] is not a run command of the workflow, or something was appended to it.",
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dependencyReviewSettings(): array
    {
        return [
            'the action, pinned to a major version' => ['uses: actions/dependency-review-action@v4'],
            'moderate and above blocks' => ['fail-on-severity: moderate'],
            // The action's own default is `runtime` alone. Naming all three is
            // the difference between the gate the documentation describes and a
            // gate that quietly ignores a vulnerable build tool.
            'every scope, not only runtime' => ['fail-on-scopes: runtime, development, unknown'],
            'the vulnerability check is on' => ['vulnerability-check: true'],
            // A warning nobody has to act on is not a gate.
            'it fails rather than warns' => ['warn-only: false'],
            'it writes no pull-request comment' => ['comment-summary-in-pr: never'],
        ];
    }

    #[Test]
    #[DataProvider('dependencyReviewSettings')]
    public function the_dependency_review_is_configured_to_block(string $directive): void
    {
        $this->assertWorkflowDeclares($directive);
    }

    /**
     * The action compares a base ref with a head ref, so it has nothing to
     * compare on a push or a scheduled run. Restricting it is what keeps those
     * runs from failing for a reason that has nothing to do with security.
     */
    #[Test]
    public function the_dependency_review_runs_only_for_a_pull_request(): void
    {
        $this->assertWorkflowDeclares("if: github.event_name == 'pull_request'");
    }

    // --- What the workflow must never do -----------------------------------

    #[Test]
    public function the_workflow_is_read_only(): void
    {
        $workflow = $this->workflow();

        $this->assertWorkflowDeclares('contents: read');
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*[a-z-]+:\s*write\s*$/mi',
            $workflow,
            'The workflow grants a write permission. It reports; it changes nothing.',
        );
        $this->assertStringNotContainsString('permissions: write-all', $workflow);
    }

    /**
     * A step that cannot fail the job is a step that reports nothing.
     */
    #[Test]
    public function no_step_swallows_its_own_failure(): void
    {
        foreach ($this->workflowRunCommands() as $command) {
            $this->assertStringNotContainsString(
                '||',
                $command,
                "[{$command}] can succeed even when the audit inside it fails.",
            );
        }

        $this->assertStringNotContainsString('continue-on-error', $this->workflow());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forbiddenDirectives(): array
    {
        return [
            // Rewrites package-lock.json unreviewed, and `--force` will install
            // a semver-major release to silence an advisory.
            'an automatic npm fix' => ['npm audit fix'],
            // Resolves outside the lock file, so the tree that gets audited is
            // not the tree that was reviewed, tested and deployed.
            'an unpinned Composer upgrade' => ['composer update'],
            // An advisory that is ignored is an advisory nobody sees again.
            'a Composer severity floor' => ['--ignore-severity'],
            'a Composer reachability exemption' => ['--ignore-unreachable'],
            'an npm threshold that blocks nothing' => ['--audit-level=none'],
            'an advisory allowlist' => ['allow-ghsas'],
            // Would move the policy into a file this test cannot see.
            'an external policy file' => ['config-file'],
            'a review that only warns' => ['warn-only: true'],
        ];
    }

    /**
     * A dependency audit that fixes or excuses things by itself is not a gate:
     * it is a process that makes the alarm stop. Every repair here is a human
     * decision, recorded in a commit (`docs/09-runbooks.md`, section 8b).
     *
     * Read with comments stripped, so the workflow can explain why each of
     * these is absent without appearing to use it.
     */
    #[Test]
    #[DataProvider('forbiddenDirectives')]
    public function the_workflow_never_repairs_or_silences_anything(string $directive): void
    {
        $this->assertStringNotContainsString($directive, $this->workflow());
    }

    // --- Helpers -----------------------------------------------------------

    private function assertWorkflowDeclares(string $directive): void
    {
        $this->assertContains(
            $directive,
            $this->workflowLines(),
            "The workflow does not declare [{$directive}].",
        );
    }

    /**
     * The workflow with its comments removed.
     *
     * Every assertion reads this rather than the raw file, in both directions:
     * a comment must not be able to satisfy a required directive, and it must
     * not be able to trip a forbidden one. Comments here are unquoted, so
     * cutting each line at its first `#` is exact enough and is the whole
     * extent of the YAML this test pretends to understand.
     */
    private function workflow(): string
    {
        $path = base_path(self::WORKFLOW);

        $this->assertFileExists($path);

        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];
        $stripped = [];

        foreach ($lines as $line) {
            $stripped[] = rtrim((string) preg_replace('/#.*$/', '', $line));
        }

        return implode("\n", $stripped);
    }

    /**
     * @return array<int, string>
     */
    private function workflowLines(): array
    {
        $lines = [];

        foreach (explode("\n", $this->workflow()) as $line) {
            $line = trim($line);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Every `run:` command in the workflow, one per entry.
     *
     * Single-line commands only, which is what the workflow uses. A block
     * scalar would capture as `|` and fail the exactness assertions rather than
     * slipping past them, which is the right direction to fail.
     *
     * @return array<int, string>
     */
    private function workflowRunCommands(): array
    {
        preg_match_all('/^[ \t]*run:[ \t]*(\S.*)$/m', $this->workflow(), $matches);

        return array_map(
            fn (string $command): string => $this->normalize($command),
            $matches[1],
        );
    }

    /**
     * The `scripts` block of a manifest, as a map of name to command.
     *
     * Composer allows a script to be a list of commands; the lines are joined
     * so a caller can ask one question of either manifest.
     *
     * @return array<string, string>
     */
    private function scriptsFrom(string $manifest): array
    {
        $decoded = $this->manifest($manifest);

        $this->assertArrayHasKey('scripts', $decoded, "{$manifest} declares no scripts.");
        $this->assertIsArray($decoded['scripts']);

        $scripts = [];

        foreach ($decoded['scripts'] as $name => $command) {
            if (! is_string($name)) {
                continue;
            }

            $scripts[$name] = is_array($command)
                ? implode("\n", array_map(static fn (mixed $line): string => (string) $line, $command))
                : (string) $command;
        }

        return $scripts;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $name): array
    {
        $path = base_path($name);

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, "{$name} is not valid JSON.");

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Whitespace collapsed, so a command is compared for what it does rather
     * than how it is spaced or wrapped.
     */
    private function normalize(string $command): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $command));
    }
}
