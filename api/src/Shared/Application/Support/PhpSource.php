<?php

declare(strict_types=1);

namespace App\Shared\Application\Support;

/**
 * A PHP file seen as CODE ONLY - comments, doc-comments and string literals blanked out.
 *
 * Every content rule of {@see DddArchitectureValidator} used to scan raw `file_get_contents`
 * output, so it could not tell code from a comment or a string. The consequence: a rule
 * matched its own documentation. Four stories each paid for that (33.13, 33.15, 33.16,
 * 33.17) with fragment-assembled literals, "this cannot self-match" comments and a
 * permanent Rector skip.
 *
 * This class removes the whole class of bug instead of working around it once more. It runs
 * `token_get_all()` and rewrites every comment/string token as spaces of the same length,
 * **preserving newlines** so byte offsets and line numbers still line up with the original
 * file. The rules therefore keep their existing patterns unchanged - they simply scan text
 * in which a quoted pattern cannot exist.
 *
 * Why blanking rather than a token walk: the patterns the rules need (an import prefix, a
 * `public function set*` shape, a `new \DateTimeImmutable()` construct) are already correct
 * as regexes. The bug was never the pattern - it was the haystack. Replacing the haystack
 * fixes all nine rules without rewriting nine patterns, and the 52 existing validator tests
 * stay the contract.
 */
final readonly class PhpSource
{
    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function __construct(
        private string $codeText,
        private string $codeWithLiterals,
        private array $tokens,
    ) {
    }

    public static function fromFile(string $path): ?self
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? self::fromString($contents) : null;
    }

    public static function fromString(string $contents): self
    {
        $tokens = token_get_all($contents);
        [$codeText, $codeWithLiterals] = self::renderViews($tokens);

        return new self($codeText, $codeWithLiterals, $tokens);
    }

    /**
     * The default view: comments gone, and every string literal's CONTENT replaced by filler.
     *
     * The content is filled, not blanked, on purpose. `new \DateTimeImmutable('now')` must not
     * collapse into `new \DateTimeImmutable(   )` - the clock rule deliberately distinguishes a
     * zero-arg wall-clock read from an argumented parse of a specific instant, and emptying the
     * argument would turn every argumented construct into a false violation. Filling keeps the
     * shape of the code while making the *prose inside* a string unmatchable.
     *
     * Use this for rules that match code SHAPES (imports, declarations, constructs, call forms).
     */
    public function codeText(): string
    {
        return $this->codeText;
    }

    /**
     * Comments gone, string literals INTACT.
     *
     * For the rare rule that legitimately matches a literal VALUE rather than a code shape -
     * `isGranted('ROLE_MEMBER')` is about the role string itself, so filling it would blind the
     * rule to the very thing it exists to catch. Such a rule is still immune to prose (comments
     * are gone) but must guard against a literal occurrence in its own source by construction.
     */
    public function codeWithLiterals(): string
    {
        return $this->codeWithLiterals;
    }

    /**
     * The string-literal values passed as the first argument to a call (or attribute) of any of
     * the named functions - `isGranted('ROLE_MEMBER')` and `#[IsGranted(attribute: 'ROLE_X')]`
     * alike.
     *
     * This is a real token walk, not a regex, and that is the whole point: a *string* that merely
     * CONTAINS the text `isGranted('ROLE_MEMBER')` is a single T_CONSTANT_ENCAPSED_STRING token -
     * never a call sequence - so it cannot match. The rule that uses this is immune to its own
     * documentation and to its own scan patterns **by construction**, which is what finally lets
     * the fragment-assembled `'ROLE_'.'MEMBER'` guard (story 33.17) be deleted.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    public function firstStringArguments(array $names): array
    {
        $found = [];
        $count = count($this->tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $this->tokens[$i];
            if (!is_array($token) || !in_array($token[1], $names, true)) {
                continue;
            }
            if (!in_array($token[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $j = $this->skipTrivia($i + 1);
            if ('(' !== ($this->tokens[$j] ?? null)) {
                continue;
            }

            $j = $this->skipTrivia($j + 1);

            // Optional named-argument form: `attribute: 'ROLE_X'`.
            $named = $this->tokens[$j] ?? null;
            if (is_array($named) && \T_STRING === $named[0]) {
                $k = $this->skipTrivia($j + 1);
                if (':' === ($this->tokens[$k] ?? null)) {
                    $j = $this->skipTrivia($k + 1);
                }
            }

            $arg = $this->tokens[$j] ?? null;
            if (is_array($arg) && \T_CONSTANT_ENCAPSED_STRING === $arg[0]) {
                $found[] = trim($arg[1], '\'"');
            }
        }

        return $found;
    }

    /**
     * Every `new X(...)` in CODE for the named classes, with just enough about its first argument
     * to tell a wall-clock read from a parse of a specific instant.
     *
     * A token walk for the same reason as firstStringArguments(): a string that merely contains
     * the text `new \DateTimeImmutable()` is one token, never a construction - so the rule cannot
     * match prose, a scan pattern, or its own source.
     *
     * `arguments` is 0 for `new X()`. `firstString` is the first argument's literal value when it
     * IS a string literal, and null otherwise (a variable, a constant, an expression) - which is
     * precisely the distinction the clock rule needs: `new X()` and `new X('now')` read the clock;
     * `new X($iso)` parses an instant.
     *
     * @param list<string> $classNames short names, e.g. ['DateTimeImmutable']
     *
     * @return list<array{class: string, arguments: int, firstString: string|null}>
     */
    public function newExpressions(array $classNames): array
    {
        $found = [];
        $count = count($this->tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $this->tokens[$i];
            if (!is_array($token) || \T_NEW !== $token[0]) {
                continue;
            }

            $j = $this->skipTrivia($i + 1);
            $name = $this->tokens[$j] ?? null;
            if (!is_array($name)
                || !in_array($name[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)
            ) {
                continue;
            }

            $short = $name[1];
            $pos = strrpos($short, '\\');
            $short = false === $pos ? $short : substr($short, $pos + 1);

            if (!in_array($short, $classNames, true)) {
                continue;
            }

            $j = $this->skipTrivia($j + 1);
            if ('(' !== ($this->tokens[$j] ?? null)) {
                continue;
            }

            $j = $this->skipTrivia($j + 1);
            $first = $this->tokens[$j] ?? null;

            if (')' === $first) {
                $found[] = ['class' => $short, 'arguments' => 0, 'firstString' => null];

                continue;
            }

            $isString = is_array($first) && \T_CONSTANT_ENCAPSED_STRING === $first[0];
            $found[] = [
                'class' => $short,
                'arguments' => 1,
                'firstString' => $isString ? trim($first[1], '\'"') : null,
            ];
        }

        return $found;
    }

    private function skipTrivia(int $i): int
    {
        $count = count($this->tokens);
        while ($i < $count) {
            $token = $this->tokens[$i];
            if (is_array($token) && in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                ++$i;

                continue;
            }
            break;
        }

        return $i;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: string, 1: string} [codeText, codeWithLiterals]
     */
    private static function renderViews(array $tokens): array
    {
        $filled = '';
        $withLiterals = '';

        foreach ($tokens as $token) {
            if (is_string($token)) {
                $filled .= $token;
                $withLiterals .= $token;

                continue;
            }

            [$id, $text] = $token;

            if (in_array($id, self::COMMENT_TOKENS, true)) {
                $filled .= self::blank($text);
                $withLiterals .= self::blank($text);

                continue;
            }

            if (in_array($id, self::LITERAL_TOKENS, true)) {
                $filled .= self::fill($text);
                $withLiterals .= $text;

                continue;
            }

            $filled .= $text;
            $withLiterals .= $text;
        }

        return [$filled, $withLiterals];
    }

    /**
     * Author prose. Never code, in any view.
     *
     * T_INLINE_HTML is anything outside `<?php`. No file the validator scans has any, and text
     * outside PHP tags cannot violate a PHP rule - so a non-PHP file blanks to nothing and no
     * rule fires on it, which is the safe direction.
     *
     * @var list<int>
     */
    private const array COMMENT_TOKENS = [
        \T_COMMENT,
        \T_DOC_COMMENT,
        \T_INLINE_HTML,
    ];

    /**
     * String literal data. Code in shape, prose in content.
     *
     * T_CONSTANT_ENCAPSED_STRING is a single-quoted or non-interpolating string.
     * T_ENCAPSED_AND_WHITESPACE is the literal chunk INSIDE an interpolating "..." or heredoc -
     * the interpolated variables themselves are separate tokens and stay code, which is correct:
     * `"{$forbidden}"` is not a literal occurrence of whatever $forbidden holds.
     *
     * @var list<int>
     */
    private const array LITERAL_TOKENS = [
        \T_CONSTANT_ENCAPSED_STRING,
        \T_ENCAPSED_AND_WHITESPACE,
    ];

    /**
     * Same length, newlines kept - so a violation's line number and any offset-based slicing
     * still point at the right place in the ORIGINAL file.
     */
    private static function blank(string $text): string
    {
        return preg_replace('/[^\n]/', ' ', $text) ?? $text;
    }

    /**
     * Keep the quotes, fill the content. `'now'` stays a non-empty argument; the prose it held
     * is gone. Newlines are preserved for the same reason as blank().
     */
    private static function fill(string $text): string
    {
        return preg_replace_callback(
            '/^([\'"]?)(.*)([\'"]?)$/s',
            static fn (array $m): string => $m[1].(preg_replace('/[^\n]/', 'x', $m[2]) ?? $m[2]).$m[3],
            $text,
        ) ?? $text;
    }
}
