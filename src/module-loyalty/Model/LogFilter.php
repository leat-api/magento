<?php

declare(strict_types=1);

namespace Leat\Loyalty\Model;

/**
 * Keeps secrets and personal data out of the Leat log files, and stops user input from forging log lines.
 * Applied by Logger, so every Leat log line goes through it.
 *
 * Two entry points, because the two kinds of input need different handling:
 * - filterContext(): structured data, masked by KEY name (a value alone does not say it is a secret).
 * - filterMessage(): finished text, cleaned by PATTERN (control chars, line breaks, bearer tokens).
 */
class LogFilter
{
    /**
     * Context keys whose string values are secrets (gift card codes, referral codes, tokens, form keys, ...).
     */
    private const SENSITIVE_KEY = '/card|code|token|password|passwd|secret|authorization|form_key/i';

    /**
     * Control characters, except tab (\x09) and the line breaks (\x0A, \x0D) that are handled separately.
     */
    private const CONTROL_CHARS = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    private const LINE_BREAK = '/\r\n|\r|\n/';

    /**
     * Indent that turns a line break into a continuation line.
     */
    private const CONTINUATION = "\n    ";

    /**
     * "Bearer <token>": group 1 is the word to keep, the token after it is replaced.
     */
    private const BEARER_TOKEN = '/(Bearer\s+)[A-Za-z0-9\-._~+\/]+=*/i';

    /**
     * Values up to this length are hidden completely, longer ones keep their last characters.
     */
    private const FULLY_HIDDEN_UP_TO = 8;

    private const VISIBLE_TAIL = 4;

    /**
     * Mask the values of sensitive keys in a log context, at any depth.
     * Only strings are masked: numbers, booleans and null under a sensitive key are left as they are.
     *
     * @param array $context
     * @return array
     */
    public function filterContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = $this->filterContext($value);
            } elseif (is_string($value) && $this->isSensitiveKey((string) $key)) {
                $context[$key] = $this->mask($value);
            }
        }

        return $context;
    }

    /**
     * Make a finished log message safe to write: no control characters, no forged lines, no bearer tokens.
     * Continuation lines are indented (instead of dropped) so stack traces stay readable but a line can never start
     * with a fake timestamp.
     *
     * @param string $message
     * @return string
     */
    public function filterMessage(string $message): string
    {
        $message = $this->replace(self::CONTROL_CHARS, '', $message);
        $message = $this->replace(self::LINE_BREAK, self::CONTINUATION, $message);
        return $this->replace(self::BEARER_TOKEN, '$1***', $message);
    }

    /**
     * @param string $key
     * @return bool
     */
    private function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match(self::SENSITIVE_KEY, $key);
    }

    /**
     * preg_replace that never loses the message: on a regex error (null) the input is returned unchanged.
     *
     * @param string $pattern
     * @param string $replacement
     * @param string $subject
     * @return string
     */
    private function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }

    /**
     * Hide a secret, keeping the last characters of long ones so entries can still be told apart.
     *
     * @param string $value
     * @return string
     */
    private function mask(string $value): string
    {
        return strlen($value) > self::FULLY_HIDDEN_UP_TO ? '***' . substr($value, -self::VISIBLE_TAIL) : '***';
    }
}
