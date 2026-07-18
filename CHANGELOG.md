# Changelog

## 0.8.0

- Refresh the AutoLabel dashboard UI: slimmer header, cleaner tabs and cards, consistent card titles, and stat tiles for queue and notification metrics
- Replace the rule form's native multi-select with the same checkbox tag grid used by notification settings, and block rule submission when no target tag is selected
- Move rarely used profile fields into a collapsible "Advanced settings" block and group related checkboxes
- Collapse raw diagnostic and last-delivery JSON payloads behind details toggles and cap preformatted block heights
- Combine paired card actions (process/clear queue, test/clear notifications, save/clear diagnostics) into single button rows
- Fix checkboxes and radios stacking above their labels inside forms, and neutralize red `:invalid` styling on untouched required selects
- Fix notification tag pickers staying visible when "any matching tag" mode is selected
- Polish dark mode with `color-scheme: dark`, dark-native secondary buttons, AA-contrast primary buttons, and a more distinct code background

## 0.5.2

- Make aggregate LLM decision parsing tolerant of nested matrix responses and common field aliases
- Avoid false `The model did not return a decision for this article and rule.` errors when the model returns an equivalent JSON shape

## 0.5.1

- Fix tag persistence diagnostics so `failed_tags` only reports newly matched target tags that cannot be resolved
- Resolve FreshRSS target tags by both `tag` and `#tag` names to avoid false failures when stored tag names include the hash prefix

## 0.5.0

- Add LLM aggregate classification so one request can classify multiple articles for one model profile
- Add LLM multi-rule classification so one article can be evaluated against multiple AutoLabel prompts in one request
- Support combined article/rule matrix responses that return each article's matched tag list
- Keep LLM aggregate batches usable without `curl_multi`; embedding batch concurrency still requires PHP curl multi support
- Update `batch_size` semantics for LLM profiles to mean the aggregate article window
- Add JSON-mode and free-form LLM request options for OpenAI-compatible and provider-specific controls
- Preserve the per-profile Thinking mode control and strip `<think>...</think>` reasoning blocks before JSON parsing
- Raise the LLM-friendly default timeout and avoid applying the short interactive timeout cap to LLM aggregate requests

## 0.1.1

- Improve automatic maintenance and cron queue draining so background runs continue processing until the queue is effectively empty
- Add author links to the dashboard header and rewrite the public English, Chinese, and French documentation
- Switch the public project license to GPL-3.0

## 0.1.0

- Initial public release of the FreshRSS AutoLabel extension
- Admin-managed model profiles
- User-managed AutoLabel rules
- LLM and embedding-based classification
- Asynchronous queue processing and backfill
- English, Simplified Chinese, and French documentation
