<?php

declare(strict_types=1);

final class AutoLabelViewState {
	/** @var array<string,mixed> */
	private static array $state = [];

	/**
	 * @param array<string,mixed> $state
	 */
	public static function replace(array $state): void {
		self::$state = $state;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		return self::$state;
	}
}

final class AutoLabelSystemProfileRepository {
	public const DEFAULT_TIMEOUT_SECONDS = 60;
	public const MAX_TIMEOUT_SECONDS = 600;
	public const DEFAULT_CONTENT_MAX_CHARS = 6000;
	public const DEFAULT_BATCH_SIZE = 25;
	public const MAX_BATCH_SIZE = 200;
	public const MAX_EMBEDDING_DIMENSIONS = 65536;
	public const MAX_EMBEDDING_NUM_CTX = 1048576;

	/** @var list<string> */
	private const PROVIDERS = ['openai', 'anthropic', 'gemini', 'ollama'];
	/** @var list<string> */
	private const MODES = ['llm', 'embedding'];
	/** @var list<string> */
	public const THINKING_MODES = ['auto', 'disabled', 'enabled'];
	public const DEFAULT_THINKING_MODE = 'auto';

	/** @var AutoLabelExtension */
	private $extension;

	public function __construct(AutoLabelExtension $extension) {
		$this->extension = $extension;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function all(): array {
		$profiles = $this->extension->profilesConfiguration();
		if (!is_array($profiles)) {
			return [];
		}

		$normalized = [];
		foreach ($profiles as $profile) {
			if (is_array($profile)) {
				$normalized[] = $this->normalizeStoredProfile($profile);
			}
		}

		usort($normalized, static fn (array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));
		return $normalized;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function enabled(): array {
		return array_values(array_filter(
			$this->all(),
			static fn (array $profile): bool => (bool)$profile['enabled']
		));
	}

	public function find(string $id): ?array {
		foreach ($this->all() as $profile) {
			if ($profile['id'] === $id) {
				return $profile;
			}
		}

		return null;
	}

	public function defaultProfile(): array {
		return [
			'id' => '',
			'name' => '',
			'provider' => 'openai',
			'model' => '',
			'base_url' => self::defaultBaseUrlForProvider('openai'),
			'api_key' => '',
			'enabled' => true,
			'profile_mode' => 'llm',
			'supports_llm' => true,
			'supports_embedding' => false,
			'timeout_seconds' => self::DEFAULT_TIMEOUT_SECONDS,
			'content_max_chars' => self::DEFAULT_CONTENT_MAX_CHARS,
			'batch_size' => self::DEFAULT_BATCH_SIZE,
			'json_mode' => true,
			'llm_options_json' => '',
			'embedding_dimensions' => 0,
			'embedding_num_ctx' => 0,
			'default_instruction' => '',
			'thinking_mode' => self::DEFAULT_THINKING_MODE,
		];
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function saveFromPayload(array $payload): array {
		$profiles = $this->all();
		$existingProfile = null;
		$requestedId = trim((string)($payload['id'] ?? ''));
		foreach ($profiles as $profile) {
			if ($requestedId !== '' && $profile['id'] === $requestedId) {
				$existingProfile = $profile;
				break;
			}
		}
		if (is_array($existingProfile) && trim((string)($payload['api_key'] ?? '')) === '') {
			$payload['api_key'] = $existingProfile['api_key'];
		}
		$normalized = $this->normalizeIncomingProfile($payload);
		$found = false;

		foreach ($profiles as $index => $profile) {
			if ($profile['id'] === $normalized['id']) {
				$profiles[$index] = $normalized;
				$found = true;
				break;
			}
		}

		if (!$found) {
			$profiles[] = $normalized;
		}

		$this->extension->saveProfilesConfiguration($profiles);
		return $normalized;
	}

	public function delete(string $id): void {
		$profiles = array_values(array_filter(
			$this->all(),
			static fn (array $profile): bool => $profile['id'] !== $id
		));
		$this->extension->saveProfilesConfiguration($profiles);
	}

	public function setEnabled(string $id, bool $enabled): void {
		$profiles = $this->all();
		foreach ($profiles as $index => $profile) {
			if ($profile['id'] === $id) {
				$profile['enabled'] = $enabled;
				$profiles[$index] = $profile;
				$this->extension->saveProfilesConfiguration($profiles);
				return;
			}
		}

		throw new RuntimeException('Unknown profile.');
	}

	/**
	 * @return list<string>
	 */
	public function providers(): array {
		return self::PROVIDERS;
	}

	/**
	 * @return list<string>
	 */
	public function modes(): array {
		return self::MODES;
	}

	public static function defaultBaseUrlForProvider(string $provider): string {
		switch ($provider) {
			case 'openai':
				return 'https://api.openai.com';
			case 'anthropic':
				return 'https://api.anthropic.com';
			case 'gemini':
				return 'https://generativelanguage.googleapis.com';
			case 'ollama':
				return 'http://127.0.0.1:11434';
			default:
				return '';
		}
	}

	public static function normalizeBatchSize(int $batchSize): int {
		return max(1, min(self::MAX_BATCH_SIZE, $batchSize));
	}

	/**
	 * @param array<string,mixed> $profile
	 * @return array<string,mixed>
	 */
	private function normalizeStoredProfile(array $profile): array {
		$profile = array_merge($this->defaultProfile(), $profile);
		$profile['id'] = is_string($profile['id']) && $profile['id'] !== '' ? $profile['id'] : 'profile_' . bin2hex(random_bytes(6));
		$profile['provider'] = in_array($profile['provider'], self::PROVIDERS, true) ? $profile['provider'] : 'openai';
		$profile['profile_mode'] = $this->normalizeProfileMode($profile);
		$profile['enabled'] = (bool)$profile['enabled'];
		$profile['supports_llm'] = $profile['profile_mode'] === 'llm';
		$profile['supports_embedding'] = $profile['profile_mode'] === 'embedding';
		$profile['timeout_seconds'] = max(3, min(self::MAX_TIMEOUT_SECONDS, (int)$profile['timeout_seconds']));
		$profile['content_max_chars'] = max(500, min(20000, (int)$profile['content_max_chars']));
		$profile['batch_size'] = self::normalizeBatchSize((int)$profile['batch_size']);
		$profile['json_mode'] = (bool)$profile['json_mode'];
		$profile['llm_options_json'] = trim(html_entity_decode((string)$profile['llm_options_json'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$profile['embedding_dimensions'] = max(0, min(self::MAX_EMBEDDING_DIMENSIONS, (int)$profile['embedding_dimensions']));
		$profile['embedding_num_ctx'] = max(0, min(self::MAX_EMBEDDING_NUM_CTX, (int)$profile['embedding_num_ctx']));
		$profile['base_url'] = trim((string)$profile['base_url']);
		if ($profile['base_url'] === '') {
			$profile['base_url'] = self::defaultBaseUrlForProvider($profile['provider']);
		}
		$profile['name'] = trim((string)$profile['name']);
		$profile['model'] = trim((string)$profile['model']);
		$profile['api_key'] = trim((string)$profile['api_key']);
		$profile['default_instruction'] = trim((string)$profile['default_instruction']);
		$thinkingMode = is_string($profile['thinking_mode'] ?? null) ? trim((string)$profile['thinking_mode']) : '';
		$profile['thinking_mode'] = in_array($thinkingMode, self::THINKING_MODES, true) ? $thinkingMode : self::DEFAULT_THINKING_MODE;

		return $profile;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function normalizeIncomingProfile(array $payload): array {
		$profile = $this->normalizeStoredProfile($payload);
		if ($profile['name'] === '') {
			throw new InvalidArgumentException('Profile name is required.');
		}
		if ($profile['model'] === '') {
			throw new InvalidArgumentException('Model is required.');
		}
		if ($profile['provider'] === 'anthropic' && $profile['supports_embedding']) {
			throw new InvalidArgumentException('Anthropic profiles can only use LLM mode.');
		}
		if ($profile['llm_options_json'] !== '') {
			$options = json_decode($profile['llm_options_json'], true);
			if (!is_array($options) || array_is_list($options)) {
				throw new InvalidArgumentException('LLM request options must be a JSON object.');
			}
		}

		return $profile;
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	private function normalizeProfileMode(array $profile): string {
		$requestedMode = trim((string)($profile['profile_mode'] ?? ''));
		if (in_array($requestedMode, self::MODES, true)) {
			return $requestedMode;
		}

		$supportsLlm = !empty($profile['supports_llm']);
		$supportsEmbedding = !empty($profile['supports_embedding']);
		if ($supportsEmbedding && !$supportsLlm) {
			return 'embedding';
		}
		if ($supportsLlm && !$supportsEmbedding) {
			return 'llm';
		}

		$model = strtolower(trim((string)($profile['model'] ?? '')));
		if ($supportsEmbedding && str_contains($model, 'embed')) {
			return 'embedding';
		}

		return 'llm';
	}
}

final class AutoLabelRuntimeBatchGate {
	/** @var array<string,int> */
	private static array $countsByProfile = [];
	/** @var array<string,array<string,bool>> */
	private static array $seenEntryKeysByProfile = [];

	/**
	 * @param array<string,mixed> $profile
	 */
	public static function claim(array $profile, FreshRSS_Entry $entry): bool {
		$profileKey = self::profileKey($profile);
		$entryKey = self::entryKey($entry);
		if (isset(self::$seenEntryKeysByProfile[$profileKey][$entryKey])) {
			return true;
		}

		if (!self::hasCapacity($profile)) {
			return false;
		}

		if (!isset(self::$seenEntryKeysByProfile[$profileKey])) {
			self::$seenEntryKeysByProfile[$profileKey] = [];
		}
		self::$seenEntryKeysByProfile[$profileKey][$entryKey] = true;
		self::$countsByProfile[$profileKey] = (self::$countsByProfile[$profileKey] ?? 0) + 1;
		return true;
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	public static function hasCapacity(array $profile): bool {
		$profileKey = self::profileKey($profile);
		return (self::$countsByProfile[$profileKey] ?? 0) < self::limitForProfile($profile);
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	private static function profileKey(array $profile): string {
		$profileId = trim((string)($profile['id'] ?? ''));
		if ($profileId !== '') {
			return $profileId;
		}

		return hash('sha256', implode('|', [
			(string)($profile['provider'] ?? ''),
			(string)($profile['model'] ?? ''),
			(string)($profile['base_url'] ?? ''),
		]));
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	private static function limitForProfile(array $profile): int {
		return AutoLabelSystemProfileRepository::normalizeBatchSize((int)($profile['batch_size'] ?? AutoLabelSystemProfileRepository::DEFAULT_BATCH_SIZE));
	}

	private static function entryKey(FreshRSS_Entry $entry): string {
		if (method_exists($entry, 'id') && (int)$entry->id() > 0) {
			return 'id:' . (string)$entry->id();
		}

		if (method_exists($entry, 'guid')) {
			$guid = trim((string)$entry->guid());
			if ($guid !== '') {
				return 'guid:' . $guid;
			}
		}

		$link = method_exists($entry, 'link') ? (string)$entry->link(true) : '';
		$title = method_exists($entry, 'title') ? (string)$entry->title() : '';
		$date = method_exists($entry, 'date') ? (string)$entry->date(true) : '';
		return hash('sha256', implode('|', [$link, $title, $date]));
	}
}

final class AutoLabelProfileCapabilityResolver {
	/**
	 * @param array<string,mixed> $profile
	 * @return list<string>
	 */
	public function modesForProfile(array $profile): array {
		$modes = [];
		if (!empty($profile['supports_llm'])) {
			$modes[] = 'llm';
		}
		if (!empty($profile['supports_embedding'])) {
			$modes[] = 'embedding';
		}
		return $modes;
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	public function supportsMode(array $profile, string $mode): bool {
		return in_array($mode, $this->modesForProfile($profile), true);
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	public function supportsInstruction(array $profile, string $mode): bool {
		return $mode === 'embedding' && $this->supportsMode($profile, 'embedding');
	}
}

final class AutoLabelUserRuleRepository {
	public const DEFAULT_THRESHOLD = 0.75;

	/** @var AutoLabelExtension */
	private $extension;
	/** @var AutoLabelSystemProfileRepository */
	private $profiles;
	/** @var AutoLabelProfileCapabilityResolver */
	private $capabilities;

	public function __construct(
		AutoLabelExtension $extension,
		AutoLabelSystemProfileRepository $profiles,
		AutoLabelProfileCapabilityResolver $capabilities
	) {
		$this->extension = $extension;
		$this->profiles = $profiles;
		$this->capabilities = $capabilities;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function all(): array {
		$rules = $this->extension->rulesConfiguration();
		if (!is_array($rules)) {
			return [];
		}

		$normalized = [];
		foreach ($rules as $rule) {
			if (is_array($rule)) {
				$normalized[] = $this->normalizeStoredRule($rule);
			}
		}

		usort($normalized, static fn (array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));
		return $normalized;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function enabled(): array {
		return array_values(array_filter(
			$this->all(),
			static fn (array $rule): bool => (bool)$rule['enabled']
		));
	}

	public function find(string $id): ?array {
		foreach ($this->all() as $rule) {
			if ($rule['id'] === $id) {
				return $rule;
			}
		}
		return null;
	}

	public function defaultRule(): array {
		return [
			'id' => '',
			'name' => '',
			'enabled' => true,
			'target_tags' => [],
			'mark_read_on_match' => false,
			'profile_id' => '',
			'mode' => 'llm',
			'llm_prompt' => '',
			'embedding_anchor_texts' => [],
			'embedding_threshold' => self::DEFAULT_THRESHOLD,
			'embedding_instruction' => '',
		];
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function saveFromPayload(array $payload): array {
		$rules = $this->all();
		$normalized = $this->normalizeIncomingRule($payload);
		$found = false;

		foreach ($rules as $index => $rule) {
			if ($rule['id'] === $normalized['id']) {
				$rules[$index] = $normalized;
				$found = true;
				break;
			}
		}

		if (!$found) {
			$rules[] = $normalized;
		}

		$this->extension->saveRulesConfiguration($rules);
		return $normalized;
	}

	public function delete(string $id): void {
		$rules = array_values(array_filter(
			$this->all(),
			static fn (array $rule): bool => $rule['id'] !== $id
		));
		$this->extension->saveRulesConfiguration($rules);
	}

	public function setEnabled(string $id, bool $enabled): void {
		$rules = $this->all();
		foreach ($rules as $index => $rule) {
			if ($rule['id'] === $id) {
				$rule['enabled'] = $enabled;
				$rules[$index] = $rule;
				$this->extension->saveRulesConfiguration($rules);
				return;
			}
		}

		throw new RuntimeException('Unknown rule.');
	}

	/**
	 * @param array<string,mixed> $rule
	 * @return array<string,mixed>
	 */
	private function normalizeStoredRule(array $rule): array {
		$rule = array_merge($this->defaultRule(), $rule);
		$rule['id'] = is_string($rule['id']) && $rule['id'] !== '' ? $rule['id'] : 'rule_' . bin2hex(random_bytes(6));
		$rule['name'] = trim((string)$rule['name']);
		$rule['enabled'] = (bool)$rule['enabled'];
		$rule['target_tags'] = $this->normalizeTargetTags(
			$rule['target_tags'] ?? ($rule['target_tag'] ?? [])
		);
		$rule['mark_read_on_match'] = (bool)$rule['mark_read_on_match'];
		$rule['profile_id'] = trim((string)$rule['profile_id']);
		$rule['mode'] = $rule['mode'] === 'embedding' ? 'embedding' : 'llm';
		$rule['llm_prompt'] = trim((string)$rule['llm_prompt']);
		$rule['embedding_anchor_texts'] = $this->normalizeAnchorTexts($rule['embedding_anchor_texts']);
		$rule['embedding_threshold'] = max(0.0, min(1.0, (float)$rule['embedding_threshold']));
		$rule['embedding_instruction'] = trim((string)$rule['embedding_instruction']);

		return $rule;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function normalizeIncomingRule(array $payload): array {
		$rule = $this->normalizeStoredRule($payload);
		if (count($rule['target_tags']) === 0) {
			throw new InvalidArgumentException('At least one target tag is required.');
		}
		if ($rule['name'] === '') {
			$rule['name'] = implode(', ', $rule['target_tags']);
		}

		$profile = $this->profiles->find($rule['profile_id']);
		if ($profile === null) {
			throw new InvalidArgumentException('Please choose a valid model profile.');
		}
		if (!$this->capabilities->supportsMode($profile, $rule['mode'])) {
			throw new InvalidArgumentException('The selected profile does not support this mode.');
		}
		if ($rule['mode'] === 'embedding' && count($rule['embedding_anchor_texts']) === 0) {
			throw new InvalidArgumentException('Embedding rules require at least one anchor text.');
		}

		return $rule;
	}

	/**
	 * @param mixed $targetTags
	 * @return list<string>
	 */
	private function normalizeTargetTags($targetTags): array {
		if (is_string($targetTags)) {
			$targetTags = [$targetTags];
		}
		if (!is_array($targetTags)) {
			return [];
		}

		$normalized = [];
		foreach ($targetTags as $targetTag) {
			$targetTag = ltrim(trim((string)$targetTag), '#');
			if ($targetTag !== '') {
				$normalized[$targetTag] = $targetTag;
			}
		}

		return array_values($normalized);
	}

	/**
	 * @return list<string>
	 */
	private function normalizeAnchorTexts($anchorTexts): array {
		if (is_string($anchorTexts)) {
			$anchorTexts = preg_split('/\R/u', $anchorTexts) ?: [];
		}
		if (!is_array($anchorTexts)) {
			return [];
		}

		$normalized = [];
		foreach ($anchorTexts as $anchorText) {
			$anchorText = trim((string)$anchorText);
			if ($anchorText !== '') {
				$normalized[$anchorText] = $anchorText;
			}
		}

		return array_values($normalized);
	}
}

final class AutoLabelEntryTextExtractor {
	/**
	 * @return array{title:string,content:string,feed:string,authors:string,url:string,text:string,embedding_text:string}
	 */
	public function extractContext(FreshRSS_Entry $entry, int $maxChars): array {
		$title = trim($entry->title());
		$content = $this->normalizeText($entry->content(false));
		$authors = method_exists($entry, 'authors') ? trim((string)$entry->authors(true)) : trim($entry->author());
		$url = trim(htmlspecialchars_decode($entry->link(true), ENT_QUOTES | ENT_HTML5));
		$feedTitle = '';
		$feed = $entry->feed();
		if ($feed !== null && method_exists($feed, 'name')) {
			$feedTitle = trim((string)$feed->name());
		}

		$text = $this->buildContextText($title, $feedTitle, $authors, $url, $content, true, $maxChars);
		$embeddingText = $this->buildContextText($title, $feedTitle, $authors, $url, $content, false, $maxChars);

		return [
			'title' => $title,
			'content' => $content,
			'feed' => $feedTitle,
			'authors' => $authors,
			'url' => $url,
			'text' => $text,
			'embedding_text' => $embeddingText,
		];
	}

	private function buildContextText(
		string $title,
		string $feedTitle,
		string $authors,
		string $url,
		string $content,
		bool $includeUrl,
		int $maxChars
	): string {
		$parts = [];
		if ($title !== '') {
			$parts[] = "Title: {$title}";
		}
		if ($feedTitle !== '') {
			$parts[] = "Feed: {$feedTitle}";
		}
		if ($authors !== '') {
			$parts[] = "Authors: {$authors}";
		}
		if ($includeUrl && $url !== '') {
			$parts[] = "URL: {$url}";
		}
		if ($content !== '') {
			$parts[] = "Content:\n{$content}";
		}

		$text = trim(implode("\n\n", $parts));
		if ($text === '') {
			$text = $title;
		}

		if (mb_strlen($text, 'UTF-8') > $maxChars) {
			$text = mb_substr($text, 0, $maxChars, 'UTF-8');
		}

		return $text;
	}

	private function normalizeText(string $html): string {
		$decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = strip_tags($decoded);
		$text = preg_replace('/\R{3,}/u', "\n\n", (string)$text) ?? '';
		$text = preg_replace('/[ \t]+/u', ' ', $text) ?? '';
		return trim($text);
	}
}

final class AutoLabelEmbeddingCacheStore {
	private const CACHE_FILE = 'embedding-cache.json';

	/** @var AutoLabelExtension */
	private $extension;

	public function __construct(AutoLabelExtension $extension) {
		$this->extension = $extension;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function read(): array {
		$content = $this->extension->readUserDataFile(self::CACHE_FILE);
		if (!is_string($content) || $content === '') {
			return [];
		}

		$data = json_decode($content, true);
		return is_array($data) ? $data : [];
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function write(array $data): void {
		$this->extension->writeUserDataFile(
			self::CACHE_FILE,
			(string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
	}

	/**
	 * @return list<float>|null
	 */
	public function get(string $key): ?array {
		$data = $this->read();
		$vector = $data[$key]['vector'] ?? null;
		if (!is_array($vector)) {
			return null;
		}
		return array_values(array_map(static fn ($value): float => (float)$value, $vector));
	}

	/**
	 * @param list<float> $vector
	 */
	public function set(string $key, array $vector): void {
		$data = $this->read();
		$data[$key] = [
			'updated_at' => time(),
			'vector' => array_values($vector),
		];
		$this->write($data);
	}
}

final class AutoLabelDiagnosticsStore {
	private const DIAGNOSTICS_FILE = 'diagnostics.json';
	private const MAX_RECORDS = 50;
	private const MAX_STRING_LENGTH = 2000;
	private const MAX_ARRAY_ITEMS = 25;
	private const MAX_DEPTH = 5;

	/** @var AutoLabelExtension */
	private $extension;

	public function __construct(AutoLabelExtension $extension) {
		$this->extension = $extension;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function all(): array {
		$content = $this->extension->readUserDataFile(self::DIAGNOSTICS_FILE);
		if (!is_string($content) || $content === '') {
			return [];
		}

		$data = json_decode($content, true);
		if (!is_array($data)) {
			return [];
		}

		return array_values(array_filter($data, 'is_array'));
	}

	/**
	 * @param array<string,mixed> $record
	 */
	public function append(array $record): void {
		if (!$this->extension->diagnosticsEnabled()) {
			return;
		}

		$records = $this->all();
		array_unshift($records, array_merge([
			'at' => date(DATE_ATOM),
		], $this->sanitizeValue($record, 0)));
		$records = array_slice($records, 0, self::MAX_RECORDS);
		$this->extension->writeUserDataFile(
			self::DIAGNOSTICS_FILE,
			(string)json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
	}

	public function clear(): void {
		$this->extension->deleteUserDataFile(self::DIAGNOSTICS_FILE);
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private function sanitizeValue($value, int $depth) {
		if ($depth >= self::MAX_DEPTH) {
			return '[truncated depth]';
		}

		if (is_string($value)) {
			if (mb_strlen($value, 'UTF-8') <= self::MAX_STRING_LENGTH) {
				return $value;
			}

			return mb_substr($value, 0, self::MAX_STRING_LENGTH, 'UTF-8') . '… [truncated]';
		}

		if (!is_array($value)) {
			return $value;
		}

		$sanitized = [];
		$count = 0;
		foreach ($value as $key => $item) {
			if ($count >= self::MAX_ARRAY_ITEMS) {
				$sanitized['__truncated__'] = 'Additional items were truncated.';
				break;
			}

			$sanitized[$key] = $this->sanitizeValue($item, $depth + 1);
			$count++;
		}

		return $sanitized;
	}
}

final class AutoLabelNotificationSettingsRepository {
	public const DEFAULT_BARK_SERVER_URL = 'https://api.day.app';
	public const DEFAULT_BARK_MAX_PER_RUN = 5;

	/** @var AutoLabelExtension */
	private $extension;

	public function __construct(AutoLabelExtension $extension) {
		$this->extension = $extension;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function settings(): array {
		return $this->normalizeStoredSettings($this->extension->notificationsConfiguration());
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function saveFromPayload(array $payload): array {
		$existing = $this->settings();
		if (trim((string)($payload['bark_device_key'] ?? '')) === '' && trim((string)($existing['bark_device_key'] ?? '')) !== '') {
			$payload['bark_device_key'] = (string)$existing['bark_device_key'];
		}
		$settings = $this->normalizeIncomingSettings($payload);
		$this->extension->saveNotificationsConfiguration($settings);
		return $settings;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function defaultSettings(): array {
		return [
			'enabled' => false,
			'tags' => [],
			'bark_tags' => [],
			'email_tags' => [],
			'bark_enabled' => false,
			'bark_server_url' => self::DEFAULT_BARK_SERVER_URL,
			'bark_device_key' => '',
			'bark_group' => 'AutoLabel',
			'bark_max_per_run' => self::DEFAULT_BARK_MAX_PER_RUN,
			'email_enabled' => false,
			'email_to' => $this->defaultEmailTo(),
			'email_subject_prefix' => '[AutoLabel]',
			'event_enabled' => false,
			'event_tags' => [],
			'event_profile_id' => '',
			'event_window_hours' => 6,
			'event_min_articles' => 5,
			'event_cooldown_hours' => 12,
			'event_max_digests_per_run' => 3,
		];
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	private function normalizeStoredSettings(array $settings): array {
		$hasBarkTags = array_key_exists('bark_tags', $settings);
		$hasEmailTags = array_key_exists('email_tags', $settings);
		$hasEventTags = array_key_exists('event_tags', $settings);
		$settings = array_merge($this->defaultSettings(), $settings);
		$settings['enabled'] = (bool)$settings['enabled'];
		$settings['tags'] = $this->normalizeTags($settings['tags'] ?? []);
		$settings['bark_tags'] = $this->normalizeTags($hasBarkTags ? $settings['bark_tags'] : $settings['tags']);
		$settings['email_tags'] = $this->normalizeTags($hasEmailTags ? $settings['email_tags'] : $settings['tags']);
		$settings['bark_enabled'] = (bool)$settings['bark_enabled'];
		$settings['bark_server_url'] = rtrim(trim((string)$settings['bark_server_url']), "/ \t\n\r\0\x0B");
		if ($settings['bark_server_url'] === '') {
			$settings['bark_server_url'] = self::DEFAULT_BARK_SERVER_URL;
		}
		$settings['bark_device_key'] = trim((string)$settings['bark_device_key']);
		$settings['bark_group'] = trim((string)$settings['bark_group']);
		if ($settings['bark_group'] === '') {
			$settings['bark_group'] = 'AutoLabel';
		}
		$settings['bark_max_per_run'] = max(0, min(100, (int)$settings['bark_max_per_run']));
		$settings['email_enabled'] = (bool)$settings['email_enabled'];
		$settings['email_to'] = trim((string)$settings['email_to']);
		$settings['email_subject_prefix'] = trim((string)$settings['email_subject_prefix']);
		if ($settings['email_subject_prefix'] === '') {
			$settings['email_subject_prefix'] = '[AutoLabel]';
		}
		$settings['event_enabled'] = (bool)$settings['event_enabled'];
		$settings['event_tags'] = $this->normalizeTags($hasEventTags ? $settings['event_tags'] : $settings['tags']);
		$settings['event_profile_id'] = trim((string)($settings['event_profile_id'] ?? ''));
		$settings['event_window_hours'] = max(1, min(168, (int)$settings['event_window_hours']));
		$settings['event_min_articles'] = max(2, min(200, (int)$settings['event_min_articles']));
		$settings['event_cooldown_hours'] = max(1, min(720, (int)$settings['event_cooldown_hours']));
		$settings['event_max_digests_per_run'] = max(1, min(10, (int)$settings['event_max_digests_per_run']));

		return $settings;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function normalizeIncomingSettings(array $payload): array {
		$settings = $this->normalizeStoredSettings($payload);
		if ($settings['enabled'] && $settings['bark_enabled'] && $settings['bark_device_key'] === '') {
			throw new InvalidArgumentException('Bark device key is required when Bark notifications are enabled.');
		}
		if ($settings['enabled'] && $settings['email_enabled'] && $settings['email_to'] === '') {
			throw new InvalidArgumentException('Email recipient is required when email notifications are enabled.');
		}
		if ($settings['enabled'] && !$settings['bark_enabled'] && !$settings['email_enabled']) {
			throw new InvalidArgumentException('Enable at least one notification channel.');
		}

		return $settings;
	}

	/**
	 * @param mixed $tags
	 * @return list<string>
	 */
	private function normalizeTags($tags): array {
		if (is_string($tags)) {
			$tags = [$tags];
		}
		if (!is_array($tags)) {
			return [];
		}

		$normalized = [];
		foreach ($tags as $tag) {
			$tag = ltrim(trim((string)$tag), '#');
			if ($tag !== '') {
				$normalized[$tag] = $tag;
			}
		}

		natcasesort($normalized);
		return array_values($normalized);
	}

	private function defaultEmailTo(): string {
		if (class_exists('FreshRSS_Context') && method_exists('FreshRSS_Context', 'hasUserConf') && FreshRSS_Context::hasUserConf()) {
			$userConf = FreshRSS_Context::userConf();
			if (is_object($userConf) && isset($userConf->mail_login)) {
				return trim((string)$userConf->mail_login);
			}
		}

		return '';
	}
}

final class AutoLabelNotificationStore {
	private const NOTIFICATION_FILE = 'notifications.json';
	private const MAX_PENDING_EMAIL_EVENTS = 1000;
	private const MAX_EVENT_CANDIDATES = 1000;
	private const MAX_EVENT_DIGESTS = 200;
	private const MAX_SENT_KEYS = 5000;
	private const SENT_TTL_SECONDS = 2592000;

	/** @var AutoLabelExtension */
	private $extension;

	public function __construct(AutoLabelExtension $extension) {
		$this->extension = $extension;
	}

	/**
	 * @return array{pending_email:int,event_candidates:int,event_digests:int,sent_email:int,sent_bark:int,sent_events:int,last_delivery:array<string,mixed>|null}
	 */
	public function summary(): array {
		$data = $this->read();
		return [
			'pending_email' => count($data['pending_email']),
			'event_candidates' => count($data['event_candidates']),
			'event_digests' => count($data['event_digests']),
			'sent_email' => count($data['sent_email']),
			'sent_bark' => count($data['sent_bark']),
			'sent_events' => count($data['sent_events']),
			'last_delivery' => is_array($data['last_delivery'] ?? null) ? $data['last_delivery'] : null,
		];
	}

	/**
	 * @param array<string,mixed> $event
	 */
	public function queueEmailEvent(array $event): bool {
		$key = trim((string)($event['key'] ?? ''));
		if ($key === '') {
			return false;
		}

		$data = $this->read();
		if (isset($data['sent_email'][$key]) || $this->hasPendingEvent($data['pending_email'], $key)) {
			return false;
		}

		array_unshift($data['pending_email'], $event);
		$data['pending_email'] = array_slice(array_values(array_filter($data['pending_email'], 'is_array')), 0, self::MAX_PENDING_EMAIL_EVENTS);
		$this->write($data);
		return true;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function pendingEmailEvents(): array {
		return $this->read()['pending_email'];
	}

	/**
	 * @param array<string,mixed> $candidate
	 */
	public function queueEventCandidate(array $candidate): bool {
		$key = trim((string)($candidate['key'] ?? ''));
		if ($key === '') {
			return false;
		}

		$data = $this->read();
		$candidates = array_values(array_filter(
			$data['event_candidates'],
			static fn (array $event): bool => (string)($event['key'] ?? '') !== $key
		));
		array_unshift($candidates, $candidate);
		$data['event_candidates'] = array_slice($candidates, 0, self::MAX_EVENT_CANDIDATES);
		$this->write($data);
		return true;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function recentEventCandidates(int $windowHours): array {
		$cutoff = time() - max(1, $windowHours) * 3600;
		$candidates = [];
		foreach ($this->read()['event_candidates'] as $candidate) {
			$timestamp = strtotime((string)($candidate['at'] ?? ''));
			if ($timestamp !== false && $timestamp >= $cutoff) {
				$candidates[] = $candidate;
			}
		}

		return $candidates;
	}

	/**
	 * @param list<array<string,mixed>> $events
	 */
	public function markEmailEventsSent(array $events): void {
		$data = $this->read();
		$sentAt = time();
		$sentKeys = [];
		foreach ($events as $event) {
			$key = trim((string)($event['key'] ?? ''));
			if ($key !== '') {
				$data['sent_email'][$key] = $sentAt;
				$sentKeys[$key] = true;
			}
		}
		$data['pending_email'] = array_values(array_filter(
			$data['pending_email'],
			static fn (array $event): bool => !isset($sentKeys[(string)($event['key'] ?? '')])
		));
		$data['sent_email'] = $this->pruneSentMap($data['sent_email']);
		$this->write($data);
	}

	public function hasBarkSent(string $key): bool {
		$data = $this->read();
		return isset($data['sent_bark'][$key]);
	}

	public function markBarkSent(string $key): void {
		if ($key === '') {
			return;
		}

		$data = $this->read();
		$data['sent_bark'][$key] = time();
		$data['sent_bark'] = $this->pruneSentMap($data['sent_bark']);
		$this->write($data);
	}

	public function hasEventSent(string $key, int $cooldownHours): bool {
		$data = $this->read();
		$sentAt = (int)($data['sent_events'][$key] ?? 0);
		return $sentAt > 0 && $sentAt >= time() - max(1, $cooldownHours) * 3600;
	}

	/**
	 * @param array<string,mixed> $digest
	 */
	public function storeEventDigest(array $digest, string $eventKey): void {
		$id = trim((string)($digest['id'] ?? ''));
		if ($id === '' || $eventKey === '') {
			return;
		}

		$data = $this->read();
		$data['sent_events'][$eventKey] = time();
		$digests = array_values(array_filter(
			$data['event_digests'],
			static fn (array $event): bool => (string)($event['id'] ?? '') !== $id
		));
		array_unshift($digests, $digest);
		$data['event_digests'] = array_slice($digests, 0, self::MAX_EVENT_DIGESTS);
		$data['sent_events'] = $this->pruneSentMap($data['sent_events']);
		$this->write($data);
	}

	public function eventDigest(string $id): ?array {
		$id = trim($id);
		if ($id === '') {
			return null;
		}
		foreach ($this->read()['event_digests'] as $digest) {
			if ((string)($digest['id'] ?? '') === $id) {
				return $digest;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $delivery
	 */
	public function setLastDelivery(array $delivery): void {
		$data = $this->read();
		$data['last_delivery'] = array_merge(['at' => date(DATE_ATOM)], $delivery);
		$this->write($data);
	}

	public function clear(): void {
		$this->extension->deleteUserDataFile(self::NOTIFICATION_FILE);
	}

	/**
	 * @return array{pending_email:list<array<string,mixed>>,event_candidates:list<array<string,mixed>>,event_digests:list<array<string,mixed>>,sent_email:array<string,int>,sent_bark:array<string,int>,sent_events:array<string,int>,last_delivery:array<string,mixed>|null}
	 */
	private function read(): array {
		$content = $this->extension->readUserDataFile(self::NOTIFICATION_FILE);
		if (!is_string($content) || $content === '') {
			return $this->emptyData();
		}

		$data = json_decode($content, true);
		if (!is_array($data)) {
			return $this->emptyData();
		}

		return [
			'pending_email' => array_values(array_filter($data['pending_email'] ?? [], 'is_array')),
			'event_candidates' => array_values(array_filter($data['event_candidates'] ?? [], 'is_array')),
			'event_digests' => array_values(array_filter($data['event_digests'] ?? [], 'is_array')),
			'sent_email' => $this->pruneSentMap(is_array($data['sent_email'] ?? null) ? $data['sent_email'] : []),
			'sent_bark' => $this->pruneSentMap(is_array($data['sent_bark'] ?? null) ? $data['sent_bark'] : []),
			'sent_events' => $this->pruneSentMap(is_array($data['sent_events'] ?? null) ? $data['sent_events'] : []),
			'last_delivery' => is_array($data['last_delivery'] ?? null) ? $data['last_delivery'] : null,
		];
	}

	/**
	 * @return array{pending_email:list<array<string,mixed>>,event_candidates:list<array<string,mixed>>,event_digests:list<array<string,mixed>>,sent_email:array<string,int>,sent_bark:array<string,int>,sent_events:array<string,int>,last_delivery:array<string,mixed>|null}
	 */
	private function emptyData(): array {
		return [
			'pending_email' => [],
			'event_candidates' => [],
			'event_digests' => [],
			'sent_email' => [],
			'sent_bark' => [],
			'sent_events' => [],
			'last_delivery' => null,
		];
	}

	/**
	 * @param array{pending_email:list<array<string,mixed>>,event_candidates:list<array<string,mixed>>,event_digests:list<array<string,mixed>>,sent_email:array<string,int>,sent_bark:array<string,int>,sent_events:array<string,int>,last_delivery:array<string,mixed>|null} $data
	 */
	private function write(array $data): void {
		$this->extension->writeUserDataFile(
			self::NOTIFICATION_FILE,
			(string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
	}

	/**
	 * @param list<array<string,mixed>> $events
	 */
	private function hasPendingEvent(array $events, string $key): bool {
		foreach ($events as $event) {
			if ((string)($event['key'] ?? '') === $key) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $map
	 * @return array<string,int>
	 */
	private function pruneSentMap(array $map): array {
		$now = time();
		$normalized = [];
		foreach ($map as $key => $timestamp) {
			$key = trim((string)$key);
			$timestamp = (int)$timestamp;
			if ($key === '' || $timestamp <= 0 || $timestamp < ($now - self::SENT_TTL_SECONDS)) {
				continue;
			}
			$normalized[$key] = $timestamp;
		}
		arsort($normalized);
		return array_slice($normalized, 0, self::MAX_SENT_KEYS, true);
	}
}

final class AutoLabelNotificationService {
	/** @var AutoLabelNotificationSettingsRepository */
	private $settings;
	/** @var AutoLabelNotificationStore */
	private $store;
	/** @var AutoLabelDiagnosticsStore */
	private $diagnostics;
	/** @var AutoLabelHttpClient */
	private $http;
	/** @var AutoLabelSystemProfileRepository|null */
	private $profiles;
	/** @var AutoLabelProviderFactory|null */
	private $providers;
	/** @var int */
	private $barkSentThisRun = 0;

	public function __construct(
		AutoLabelNotificationSettingsRepository $settings,
		AutoLabelNotificationStore $store,
		AutoLabelDiagnosticsStore $diagnostics,
		AutoLabelHttpClient $http,
		?AutoLabelSystemProfileRepository $profiles = null,
		?AutoLabelProviderFactory $providers = null
	) {
		$this->settings = $settings;
		$this->store = $store;
		$this->diagnostics = $diagnostics;
		$this->http = $http;
		$this->profiles = $profiles;
		$this->providers = $providers;
	}

	/**
	 * @param list<string> $beforeTags
	 * @param array<string,mixed> $persist
	 * @param list<array<string,mixed>> $results
	 */
	public function recordMatches(FreshRSS_Entry $entry, array $beforeTags, array $persist, array $results, string $source): void {
		$settings = $this->settings->settings();
		if (empty($settings['enabled'])) {
			return;
		}

		$newTags = $this->newlyAppliedTags($beforeTags, $persist);
		if (count($newTags) === 0) {
			return;
		}

		$emailTriggerTags = !empty($settings['email_enabled']) ? $this->matchingChannelTags($settings, 'email_tags', $newTags) : [];
		$barkTriggerTags = !empty($settings['bark_enabled']) ? $this->matchingChannelTags($settings, 'bark_tags', $newTags) : [];
		$eventTriggerTags = !empty($settings['event_enabled']) ? $this->matchingChannelTags($settings, 'event_tags', $newTags) : [];
		if (count($emailTriggerTags) === 0 && count($barkTriggerTags) === 0 && count($eventTriggerTags) === 0) {
			return;
		}

		$event = $this->buildEvent($entry, $newTags, $results, $source);
		$event['trigger_tags'] = array_values(array_unique(array_merge($emailTriggerTags, $barkTriggerTags, $eventTriggerTags)));
		if (count($emailTriggerTags) > 0) {
			$event['email_trigger_tags'] = $emailTriggerTags;
			$this->store->queueEmailEvent($event);
		}
		if (count($eventTriggerTags) > 0) {
			$event['event_trigger_tags'] = $eventTriggerTags;
			$this->store->queueEventCandidate($event);
		}
		if (count($barkTriggerTags) > 0) {
			$event['bark_trigger_tags'] = $barkTriggerTags;
			$this->sendBarkEventIfAllowed($settings, $event);
		}
	}

	/**
	 * @return array{sent:bool,count:int,error?:string}
	 */
	public function flushEmailDigest(): array {
		$settings = $this->settings->settings();
		if (empty($settings['enabled']) || empty($settings['email_enabled'])) {
			return ['sent' => false, 'count' => 0];
		}
		if (trim((string)($settings['email_to'] ?? '')) === '') {
			return ['sent' => false, 'count' => 0, 'error' => 'Email recipient is empty.'];
		}

		$events = $this->store->pendingEmailEvents();
		if (count($events) === 0) {
			return ['sent' => false, 'count' => 0];
		}

		$subject = $this->emailSubject($settings, $events);
		$body = $this->emailBody($events);
		try {
			$sent = $this->sendPlainEmail((string)$settings['email_to'], $subject, $body);
		} catch (Throwable $throwable) {
			$sent = false;
			$error = $throwable->getMessage();
		}

		if ($sent) {
			$this->store->markEmailEventsSent($events);
			$this->store->setLastDelivery([
				'channel' => 'email',
				'ok' => true,
				'count' => count($events),
				'to' => (string)$settings['email_to'],
			]);
			$this->diagnostics->append([
				'type' => 'notification_email_digest',
				'count' => count($events),
				'to' => (string)$settings['email_to'],
			]);
			return ['sent' => true, 'count' => count($events)];
		}

		$error = $error ?? 'Email delivery failed.';
		$this->store->setLastDelivery([
			'channel' => 'email',
			'ok' => false,
			'count' => count($events),
			'error' => $error,
		]);
		$this->diagnostics->append([
			'type' => 'notification_email_error',
			'count' => count($events),
			'error' => $error,
		]);
		return ['sent' => false, 'count' => count($events), 'error' => $error];
	}

	/**
	 * @return array{sent:bool,count:int,error?:string}
	 */
	public function flushEventDigest(): array {
		$settings = $this->settings->settings();
		if (empty($settings['enabled']) || empty($settings['event_enabled'])) {
			return ['sent' => false, 'count' => 0];
		}

		$candidates = $this->store->recentEventCandidates((int)$settings['event_window_hours']);
		if (count($candidates) < (int)$settings['event_min_articles']) {
			return ['sent' => false, 'count' => 0];
		}
		$candidates = array_slice($candidates, 0, 80);

		$profile = $this->eventAggregationProfile($settings);
		if (!is_array($profile) || $this->providers === null) {
			$this->diagnostics->append([
				'type' => 'notification_event_skipped',
				'reason' => 'no_llm_profile',
				'candidate_count' => count($candidates),
			]);
			return ['sent' => false, 'count' => 0, 'error' => 'No enabled LLM profile is available for event aggregation.'];
		}

		try {
			$provider = $this->providers->create((string)$profile['provider']);
			$request = $provider->buildTextRequest(
				$profile,
				'You cluster RSS articles into real-world events. Return JSON only in the form {"events":[{"event_key":"stable-short-key","title":"short event title","summary":"one sentence","article_indexes":[0,1],"importance":"high|medium|low"}]}. Only group articles that describe the same concrete event, announcement, incident, release, policy change, or trend signal.',
				$this->eventAggregationPrompt($candidates, $settings),
				2400
			);
			$response = $this->http->postJson(
				(string)$request['url'],
				$request['payload'],
				$request['headers'],
				(int)$request['timeout_seconds']
			);
			$groups = $this->parseEventAggregationResponse($provider->parseTextResponse($response));
		} catch (Throwable $throwable) {
			$this->diagnostics->append([
				'type' => 'notification_event_error',
				'error' => $throwable->getMessage(),
				'candidate_count' => count($candidates),
			]);
			return ['sent' => false, 'count' => 0, 'error' => $throwable->getMessage()];
		}

		$sent = 0;
		foreach ($groups as $group) {
			if ($sent >= (int)$settings['event_max_digests_per_run']) {
				break;
			}
			$articles = $this->articlesForEventGroup($group, $candidates);
			if (count($articles) < (int)$settings['event_min_articles']) {
				continue;
			}

			$eventKey = $this->eventFingerprint($group, $articles);
			if ($this->store->hasEventSent($eventKey, (int)$settings['event_cooldown_hours'])) {
				continue;
			}

			$digest = $this->buildEventDigest($group, $articles, $eventKey);
			$this->store->storeEventDigest($digest, $eventKey);
			if (!empty($settings['email_enabled'])) {
				$this->store->queueEmailEvent($digest);
			}
			if (!empty($settings['bark_enabled'])) {
				$this->sendEventDigestBark($settings, $digest);
			}
			++$sent;
		}

		if ($sent > 0) {
			$this->diagnostics->append([
				'type' => 'notification_event_digest',
				'count' => $sent,
				'candidate_count' => count($candidates),
			]);
		}

		return ['sent' => $sent > 0, 'count' => $sent];
	}

	/**
	 * @return array{bark:bool,email:bool}
	 */
	public function sendTest(): array {
		$settings = $this->settings->settings();
		if (empty($settings['enabled'])) {
			throw new RuntimeException('Notifications are disabled.');
		}

		$event = [
			'key' => 'test:' . bin2hex(random_bytes(6)),
			'at' => date(DATE_ATOM),
			'source' => 'test',
			'tag' => 'AutoLabel',
			'tags' => ['AutoLabel'],
			'title' => 'AutoLabel notification test',
			'feed' => '',
			'url' => $this->siteUrl(),
			'rule_names' => ['Test'],
			'reasons' => ['This is a test notification from AutoLabel.'],
		];

		$result = ['bark' => false, 'email' => false];
		if (!empty($settings['bark_enabled'])) {
			$result['bark'] = $this->sendBarkEvent($settings, $event);
		}
		if (!empty($settings['email_enabled'])) {
			$result['email'] = $this->sendPlainEmail(
				(string)$settings['email_to'],
				$this->emailSubject($settings, [$event]),
				$this->emailBody([$event])
			);
		}
		$this->store->setLastDelivery([
			'channel' => 'test',
			'ok' => $result['bark'] || $result['email'],
			'bark' => $result['bark'],
			'email' => $result['email'],
		]);

		return $result;
	}

	/**
	 * @param list<string> $beforeTags
	 * @param array<string,mixed> $persist
	 * @return list<string>
	 */
	private function newlyAppliedTags(array $beforeTags, array $persist): array {
		$before = array_fill_keys($this->normalizeTags($beforeTags), true);
		$failed = array_fill_keys($this->normalizeTags(is_array($persist['failed_tags'] ?? null) ? $persist['failed_tags'] : []), true);
		$newTags = [];
		foreach ($this->normalizeTags(is_array($persist['applied_tags'] ?? null) ? $persist['applied_tags'] : []) as $tag) {
			if (!isset($before[$tag]) && !isset($failed[$tag])) {
				$newTags[$tag] = $tag;
			}
		}
		return array_values($newTags);
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function tagIsEnabled(array $settings, string $tag): bool {
		$tags = is_array($settings['tags'] ?? null) ? $settings['tags'] : [];
		if (count($tags) === 0) {
			return true;
		}

		return in_array($tag, $tags, true);
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param list<string> $newTags
	 * @return list<string>
	 */
	private function matchingChannelTags(array $settings, string $settingsKey, array $newTags): array {
		$enabledTags = is_array($settings[$settingsKey] ?? null) ? $settings[$settingsKey] : (is_array($settings['tags'] ?? null) ? $settings['tags'] : []);
		if (count($enabledTags) === 0) {
			return array_values($newTags);
		}

		$enabled = array_fill_keys($this->normalizeTags($enabledTags), true);
		$matches = [];
		foreach ($newTags as $tag) {
			if (isset($enabled[$tag])) {
				$matches[$tag] = $tag;
			}
		}

		return array_values($matches);
	}

	/**
	 * @param list<string> $tags
	 * @param list<array<string,mixed>> $results
	 * @return array<string,mixed>
	 */
	private function buildEvent(FreshRSS_Entry $entry, array $tags, array $results, string $source): array {
		$tags = $this->normalizeTags($tags);
		$primaryTag = $tags[0] ?? 'AutoLabel';
		$details = $this->matchingDetailsForTags($tags, $results);
		$url = method_exists($entry, 'link') ? trim(htmlspecialchars_decode((string)$entry->link(true), ENT_QUOTES | ENT_HTML5)) : '';
		$feedTitle = '';
		$feed = method_exists($entry, 'feed') ? $entry->feed() : null;
		if ($feed !== null && method_exists($feed, 'name')) {
			$feedTitle = trim((string)$feed->name());
		}

		return [
			'key' => hash('sha256', $this->entryKey($entry)),
			'at' => date(DATE_ATOM),
			'source' => $source,
			'tag' => $primaryTag,
			'tags' => $tags,
			'title' => trim((string)$entry->title()),
			'feed' => $feedTitle,
			'url' => $url,
			'entry_id' => method_exists($entry, 'id') ? (int)$entry->id() : 0,
			'feed_id' => method_exists($entry, 'feedId') ? (int)$entry->feedId() : 0,
			'rule_names' => $details['rule_names'],
			'reasons' => $details['reasons'],
		];
	}

	/**
	 * @param list<string> $tags
	 * @param list<array<string,mixed>> $results
	 * @return array{rule_names:list<string>,reasons:list<string>}
	 */
	private function matchingDetailsForTags(array $tags, array $results): array {
		$enabledTags = array_fill_keys($this->normalizeTags($tags), true);
		$ruleNames = [];
		$reasons = [];
		foreach ($results as $result) {
			if (empty($result['matched'])) {
				continue;
			}
			$targetTags = $this->normalizeTags(is_array($result['target_tags'] ?? null) ? $result['target_tags'] : []);
			if (!$this->tagsIntersect($enabledTags, $targetTags)) {
				continue;
			}
			$ruleName = trim((string)($result['rule_name'] ?? ''));
			if ($ruleName !== '') {
				$ruleNames[$ruleName] = $ruleName;
			}
			$reason = trim((string)($result['reason'] ?? ''));
			if ($reason !== '') {
				$reasons[$reason] = $reason;
			}
		}

		return [
			'rule_names' => array_values($ruleNames),
			'reasons' => array_values($reasons),
		];
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $event
	 */
	private function sendBarkEventIfAllowed(array $settings, array $event): void {
		$key = (string)($event['key'] ?? '');
		if ($key === '' || $this->store->hasBarkSent($key)) {
			return;
		}
		$maxPerRun = max(0, (int)($settings['bark_max_per_run'] ?? AutoLabelNotificationSettingsRepository::DEFAULT_BARK_MAX_PER_RUN));
		if ($this->barkSentThisRun >= $maxPerRun) {
			$this->diagnostics->append([
				'type' => 'notification_bark_skipped',
				'reason' => 'max_per_run',
				'tag' => $this->eventTagSummary($event),
				'title' => (string)($event['title'] ?? ''),
			]);
			return;
		}

		if ($this->sendBarkEvent($settings, $event)) {
			$this->store->markBarkSent($key);
			++$this->barkSentThisRun;
		}
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $event
	 */
	private function sendBarkEvent(array $settings, array $event): bool {
		$serverUrl = rtrim((string)($settings['bark_server_url'] ?? ''), '/');
		$deviceKey = trim((string)($settings['bark_device_key'] ?? ''));
		if ($serverUrl === '' || $deviceKey === '') {
			return false;
		}

		$url = $serverUrl . '/push';
		$payload = [
			'device_key' => $deviceKey,
			'title' => $this->truncate($this->eventTagSummary($event) . ' ' . (string)$event['title'], 120),
			'body' => $this->barkBody($event),
			'url' => (string)($event['url'] ?? ''),
			'group' => (string)($settings['bark_group'] ?? 'AutoLabel'),
		];

		try {
			$this->http->postJson($url, $payload, [], 8);
			$this->store->setLastDelivery([
				'channel' => 'bark',
				'ok' => true,
				'tag' => $this->eventTagSummary($event),
				'title' => (string)($event['title'] ?? ''),
			]);
			return true;
		} catch (Throwable $throwable) {
			$this->store->setLastDelivery([
				'channel' => 'bark',
				'ok' => false,
				'error' => $throwable->getMessage(),
			]);
			$this->diagnostics->append([
				'type' => 'notification_bark_error',
				'error' => $throwable->getMessage(),
				'tag' => $this->eventTagSummary($event),
				'title' => (string)($event['title'] ?? ''),
			]);
			return false;
		}
	}

	/**
	 * @param array<string,mixed> $event
	 */
	private function barkBody(array $event): string {
		$lines = [];
		$feed = trim((string)($event['feed'] ?? ''));
		if ($feed !== '') {
			$lines[] = $feed;
		}
		$tagSummary = $this->eventTagSummary($event);
		if ($tagSummary !== '') {
			$lines[] = 'Tags: ' . $tagSummary;
		}
		$ruleNames = is_array($event['rule_names'] ?? null) ? $event['rule_names'] : [];
		if (count($ruleNames) > 0) {
			$lines[] = 'Rule: ' . implode(', ', array_map('strval', $ruleNames));
		}
		$reasons = is_array($event['reasons'] ?? null) ? $event['reasons'] : [];
		if (count($reasons) > 0) {
			$lines[] = $this->truncate((string)$reasons[0], 180);
		}
		if (count($lines) === 0) {
			$title = trim((string)($event['title'] ?? ''));
			$lines[] = $title !== '' ? $title : 'AutoLabel matched a new article.';
		}
		return implode("\n", $lines);
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param list<array<string,mixed>> $events
	 */
	private function emailSubject(array $settings, array $events): string {
		$count = count($events);
		$prefix = trim((string)($settings['email_subject_prefix'] ?? '[AutoLabel]'));
		return trim($prefix . ' ' . $count . ' new match' . ($count === 1 ? '' : 'es'));
	}

	/**
	 * @param list<array<string,mixed>> $events
	 */
	private function emailBody(array $events): string {
		$lines = [
			'AutoLabel notification digest',
			'Generated at: ' . date(DATE_ATOM),
			'',
		];
		foreach ($events as $event) {
			if (($event['kind'] ?? '') === 'event_digest') {
				$lines[] = '== Event: ' . trim((string)($event['event_title'] ?? $event['title'] ?? 'AutoLabel event')) . ' ==';
				$summary = trim((string)($event['summary'] ?? ''));
				if ($summary !== '') {
					$lines[] = 'Summary: ' . $summary;
				}
				$tagSummary = $this->eventTagSummary($event);
				if ($tagSummary !== '') {
					$lines[] = 'Tags: ' . $tagSummary;
				}
				$feeds = is_array($event['feeds'] ?? null) ? array_values(array_filter(array_map('strval', $event['feeds']))) : [];
				if (count($feeds) > 0) {
					$lines[] = 'Feeds: ' . implode(', ', array_slice($feeds, 0, 8));
				}
				$url = trim((string)($event['url'] ?? ''));
				if ($url !== '') {
					$lines[] = 'Details: ' . $url;
				}
				$articles = is_array($event['articles'] ?? null) ? array_values(array_filter($event['articles'], 'is_array')) : [];
				if (count($articles) > 0) {
					$lines[] = 'Articles:';
					foreach ($articles as $article) {
						$articleTitle = trim((string)($article['title'] ?? 'Untitled'));
						$lines[] = '  - ' . ($articleTitle !== '' ? $articleTitle : 'Untitled');
						$articleFeed = trim((string)($article['feed'] ?? ''));
						if ($articleFeed !== '') {
							$lines[] = '    Feed: ' . $articleFeed;
						}
						$articleUrl = trim((string)($article['url'] ?? ''));
						if ($articleUrl !== '') {
							$lines[] = '    Source: ' . $articleUrl;
						}
					}
				}
				$lines[] = '';
				continue;
			}

			$title = trim((string)($event['title'] ?? 'Untitled'));
			$lines[] = '- ' . ($title !== '' ? $title : 'Untitled');
			$tagSummary = $this->eventTagSummary($event);
			if ($tagSummary !== '') {
				$lines[] = '  Tags: ' . $tagSummary;
			}
			$feed = trim((string)($event['feed'] ?? ''));
			if ($feed !== '') {
				$lines[] = '  Feed: ' . $feed;
			}
			$ruleNames = is_array($event['rule_names'] ?? null) ? array_values(array_filter(array_map('strval', $event['rule_names']))) : [];
			if (count($ruleNames) > 0) {
				$lines[] = '  Rule: ' . implode(', ', $ruleNames);
			}
			$url = trim((string)($event['url'] ?? ''));
			if ($url !== '') {
				$lines[] = '  Source: ' . $url;
			}
			$reasons = is_array($event['reasons'] ?? null) ? array_values(array_filter(array_map('strval', $event['reasons']))) : [];
			if (count($reasons) > 0) {
				$lines[] = '  Reason: ' . $this->truncate((string)$reasons[0], 300);
			}
			$lines[] = '';
		}

		return implode("\n", $lines);
	}

	/**
	 * @param array<string,bool> $enabledTags
	 * @param list<string> $targetTags
	 */
	private function tagsIntersect(array $enabledTags, array $targetTags): bool {
		foreach ($targetTags as $tag) {
			if (isset($enabledTags[$tag])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $event
	 * @return list<string>
	 */
	private function eventTags(array $event): array {
		$tags = $this->normalizeTags(is_array($event['tags'] ?? null) ? $event['tags'] : []);
		if (count($tags) === 0) {
			$tags = $this->normalizeTags((string)($event['tag'] ?? ''));
		}

		return count($tags) > 0 ? $tags : ['AutoLabel'];
	}

	/**
	 * @param array<string,mixed> $event
	 */
	private function eventTagSummary(array $event): string {
		return implode(' ', array_map(static fn (string $tag): string => '#' . $tag, $this->eventTags($event)));
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>|null
	 */
	private function eventAggregationProfile(array $settings): ?array {
		if ($this->profiles === null) {
			return null;
		}
		$profileId = trim((string)($settings['event_profile_id'] ?? ''));
		if ($profileId !== '') {
			$profile = $this->profiles->find($profileId);
			if (is_array($profile) && !empty($profile['enabled']) && ($profile['profile_mode'] ?? 'llm') === 'llm') {
				return $profile;
			}
		}
		foreach ($this->profiles->enabled() as $profile) {
			if (($profile['profile_mode'] ?? 'llm') === 'llm') {
				return $profile;
			}
		}

		return null;
	}

	/**
	 * @param list<array<string,mixed>> $candidates
	 * @param array<string,mixed> $settings
	 */
	private function eventAggregationPrompt(array $candidates, array $settings): string {
		$articles = [];
		foreach ($candidates as $index => $candidate) {
			$reasons = is_array($candidate['reasons'] ?? null) ? array_values(array_filter(array_map('strval', $candidate['reasons']))) : [];
			$articles[] = [
				'index' => $index,
				'title' => $this->truncate(trim((string)($candidate['title'] ?? '')), 220),
				'feed' => $this->truncate(trim((string)($candidate['feed'] ?? '')), 120),
				'tags' => $this->eventTags($candidate),
				'reason' => $this->truncate((string)($reasons[0] ?? ''), 260),
				'url' => trim((string)($candidate['url'] ?? '')),
			];
		}

		return implode("\n", [
			'Cluster the following AutoLabel notification candidates from the last ' . (int)$settings['event_window_hours'] . ' hour(s).',
			'Only create an event when at least ' . (int)$settings['event_min_articles'] . ' articles clearly describe the same concrete thing.',
			'Articles may come from the same feed; group them when they describe the same concrete event, development, or repeated signal. Do not group articles merely because they share a broad topic or tag.',
			'Use the zero-based article index values exactly as provided.',
			'Return JSON only.',
			json_encode(['articles' => $articles], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		]);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function parseEventAggregationResponse(string $text): array {
		$data = $this->decodeJsonObject($text);
		if (!is_array($data)) {
			return [];
		}
		$events = is_array($data['events'] ?? null) ? $data['events'] : [];
		return array_values(array_filter($events, 'is_array'));
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function decodeJsonObject(string $text): ?array {
		$text = trim($text);
		$decoded = json_decode($text, true);
		if (is_array($decoded)) {
			return $decoded;
		}
		$start = strpos($text, '{');
		$end = strrpos($text, '}');
		if ($start === false || $end === false || $end <= $start) {
			return null;
		}
		$decoded = json_decode(substr($text, $start, $end - $start + 1), true);
		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * @param array<string,mixed> $group
	 * @param list<array<string,mixed>> $candidates
	 * @return list<array<string,mixed>>
	 */
	private function articlesForEventGroup(array $group, array $candidates): array {
		$indexes = is_array($group['article_indexes'] ?? null) ? $group['article_indexes'] : [];
		$articles = [];
		foreach ($indexes as $index) {
			if (!is_numeric($index)) {
				continue;
			}
			$index = (int)$index;
			if (isset($candidates[$index]) && is_array($candidates[$index])) {
				$articles[(string)($candidates[$index]['key'] ?? $index)] = $candidates[$index];
			}
		}

		return array_values($articles);
	}

	/**
	 * @param list<array<string,mixed>> $articles
	 */
	private function distinctFeedCount(array $articles): int {
		$feeds = [];
		foreach ($articles as $article) {
			$feed = trim((string)($article['feed'] ?? ''));
			if ($feed !== '') {
				$feeds[$feed] = true;
			}
		}

		return count($feeds);
	}

	/**
	 * @param array<string,mixed> $group
	 * @param list<array<string,mixed>> $articles
	 */
	private function eventFingerprint(array $group, array $articles): string {
		$raw = trim((string)($group['event_key'] ?? ''));
		if ($raw === '') {
			$raw = trim((string)($group['title'] ?? ''));
		}
		if ($raw === '') {
			$raw = implode('|', array_map(static fn (array $article): string => (string)($article['title'] ?? ''), $articles));
		}
		$normalized = strtolower(trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw));
		return hash('sha256', $normalized);
	}

	/**
	 * @param array<string,mixed> $group
	 * @param list<array<string,mixed>> $articles
	 * @return array<string,mixed>
	 */
	private function buildEventDigest(array $group, array $articles, string $eventKey): array {
		$title = trim((string)($group['title'] ?? ''));
		if ($title === '') {
			$title = 'AutoLabel event digest';
		}
		$summary = trim((string)($group['summary'] ?? ''));
		$articleKeys = array_map(static fn (array $article): string => (string)($article['key'] ?? ''), $articles);
		sort($articleKeys);
		$id = substr(hash('sha256', $eventKey . '|' . implode('|', $articleKeys)), 0, 24);
		$feeds = [];
		$tags = [];
		$normalizedArticles = [];
		foreach ($articles as $article) {
			$feed = trim((string)($article['feed'] ?? ''));
			if ($feed !== '') {
				$feeds[$feed] = $feed;
			}
			foreach ($this->eventTags($article) as $tag) {
				$tags[$tag] = $tag;
			}
			$normalizedArticles[] = [
				'title' => trim((string)($article['title'] ?? 'Untitled')),
				'feed' => $feed,
				'url' => trim((string)($article['url'] ?? '')),
				'tags' => $this->eventTags($article),
				'reasons' => is_array($article['reasons'] ?? null) ? array_values(array_filter(array_map('strval', $article['reasons']))) : [],
			];
		}

		return [
			'kind' => 'event_digest',
			'id' => $id,
			'key' => 'event_digest:' . $id,
			'at' => date(DATE_ATOM),
			'source' => 'event_aggregation',
			'tag' => array_values($tags)[0] ?? 'AutoLabel',
			'tags' => array_values($tags),
			'title' => 'Event: ' . $title,
			'event_title' => $title,
			'summary' => $summary,
			'feed' => count($normalizedArticles) . ' articles / ' . count($feeds) . ' feeds',
			'feeds' => array_values($feeds),
			'article_count' => count($normalizedArticles),
			'feed_count' => count($feeds),
			'articles' => $normalizedArticles,
			'url' => $this->eventDetailUrl($id),
			'rule_names' => ['Event aggregation'],
			'reasons' => $summary !== '' ? [$summary] : [],
			'importance' => trim((string)($group['importance'] ?? '')),
		];
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $digest
	 */
	private function sendEventDigestBark(array $settings, array $digest): void {
		$key = (string)($digest['key'] ?? '');
		if ($key === '' || $this->store->hasBarkSent($key)) {
			return;
		}
		if ($this->sendBarkEvent($settings, $digest)) {
			$this->store->markBarkSent($key);
		}
	}

	private function eventDetailUrl(string $id): string {
		if (class_exists('Minz_Url')) {
			return Minz_Url::display(['c' => 'autolabel', 'a' => 'event', 'params' => ['id' => $id]], 'html', true);
		}

		return '';
	}

	private function sendPlainEmail(string $to, string $subject, string $body): bool {
		$to = trim($to);
		if ($to === '') {
			return false;
		}

		if (class_exists('PHPMailer\\PHPMailer\\PHPMailer') && class_exists('Minz_Configuration')) {
			return $this->sendPlainEmailWithPhpMailer($to, $subject, $body);
		}

		$headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
		return function_exists('mail') && @mail($to, $subject, $body, $headers);
	}

	private function sendPlainEmailWithPhpMailer(string $to, string $subject, string $body): bool {
		$conf = Minz_Configuration::get('system');
		$smtp = is_array($conf->smtp ?? null) ? $conf->smtp : [];
		\PHPMailer\PHPMailer\PHPMailer::$validator = 'html5';
		$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
		try {
			$mail->Debugoutput = 'error_log';
			$mail->SMTPDebug = (string)($conf->environment ?? '') === 'development' ? 2 : 0;
			if ((string)($conf->mailer ?? '') === 'smtp') {
				$mail->isSMTP();
				$mail->Hostname = (string)($smtp['hostname'] ?? '');
				$mail->Host = (string)($smtp['host'] ?? '');
				$mail->SMTPAuth = (bool)($smtp['auth'] ?? false);
				$mail->Username = (string)($smtp['username'] ?? '');
				$mail->Password = (string)($smtp['password'] ?? '');
				$mail->SMTPSecure = (string)($smtp['secure'] ?? '');
				$mail->Port = (int)($smtp['port'] ?? 25);
			} else {
				$mail->isMail();
			}

			$from = trim((string)($smtp['from'] ?? ''));
			if ($from === '') {
				$from = 'noreply@localhost';
			}
			$mail->setFrom($from, 'AutoLabel');
			$mail->addAddress($to);
			$mail->isHTML(false);
			$mail->CharSet = 'utf-8';
			$mail->Subject = $subject;
			$mail->Body = $body;
			$mail->send();
			return true;
		} catch (Throwable $throwable) {
			if (class_exists('Minz_Log')) {
				Minz_Log::warning('AutoLabel notification email failed: ' . $throwable->getMessage());
			}
			return false;
		}
	}

	/**
	 * @param mixed $tags
	 * @return list<string>
	 */
	private function normalizeTags($tags): array {
		if (is_string($tags)) {
			$tags = [$tags];
		}
		if (!is_array($tags)) {
			return [];
		}

		$normalized = [];
		foreach ($tags as $tag) {
			$tag = ltrim(trim((string)$tag), '#');
			if ($tag !== '') {
				$normalized[$tag] = $tag;
			}
		}
		return array_values($normalized);
	}

	private function entryKey(FreshRSS_Entry $entry): string {
		if (method_exists($entry, 'id') && (int)$entry->id() > 0) {
			return 'id:' . (string)$entry->id();
		}
		if (method_exists($entry, 'guid')) {
			$guid = trim((string)$entry->guid());
			if ($guid !== '') {
				return 'guid:' . (string)(method_exists($entry, 'feedId') ? (int)$entry->feedId() : 0) . ':' . $guid;
			}
		}

		$link = method_exists($entry, 'link') ? trim((string)$entry->link(true)) : '';
		$title = method_exists($entry, 'title') ? trim((string)$entry->title()) : '';
		$date = method_exists($entry, 'date') ? (string)$entry->date(true) : '';
		return hash('sha256', implode('|', [$link, $title, $date]));
	}

	private function siteUrl(): string {
		if (class_exists('Minz_Url')) {
			return Minz_Url::display(['c' => 'index', 'a' => 'index'], 'html', true);
		}

		return '';
	}

	private function truncate(string $value, int $maxLength): string {
		if ($maxLength <= 0) {
			return '';
		}
		if (function_exists('mb_strlen') && function_exists('mb_substr')) {
			return mb_strlen($value, 'UTF-8') <= $maxLength
				? $value
				: mb_substr($value, 0, max(0, $maxLength - 1), 'UTF-8') . '...';
		}

		return strlen($value) <= $maxLength ? $value : substr($value, 0, max(0, $maxLength - 3)) . '...';
	}
}

final class AutoLabelQueueStore {
	private const QUEUE_FILE = 'queue.json';
	private const MANUAL_RUN_FILE = 'queue-run.json';
	private const MAX_ITEMS = 5000;

	/** @var AutoLabelExtension */
	private $extension;

	public function __construct(AutoLabelExtension $extension) {
		$this->extension = $extension;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function allItems(): array {
		$data = $this->read();
		return array_values(array_filter($data['items'] ?? [], 'is_array'));
	}

	public function version(): int {
		$data = $this->read();
		return max(0, (int)($data['version'] ?? 0));
	}

	/**
	 * @return array{pending_entries:int,pending_backfills:int,pending_backfill_entries:int,last_run:array<string,mixed>|null}
	 */
	public function snapshot(): array {
		$items = $this->allItems();
		$pendingEntries = 0;
		$pendingBackfills = 0;
		$pendingBackfillEntries = 0;
		foreach ($items as $item) {
			if (($item['type'] ?? '') === 'entry') {
				++$pendingEntries;
			} elseif (($item['type'] ?? '') === 'backfill') {
				++$pendingBackfills;
				$state = is_array($item['state'] ?? null) ? $item['state'] : [];
				$limit = max(0, (int)($state['limit'] ?? 0));
				$processed = max(0, (int)($state['processed'] ?? 0));
				$pendingBackfillEntries += max(0, $limit - $processed);
			}
		}

		$data = $this->read();
		$lastRun = is_array($data['last_run'] ?? null) ? $data['last_run'] : null;

		return [
			'pending_entries' => $pendingEntries,
			'pending_backfills' => $pendingBackfills,
			'pending_backfill_entries' => $pendingBackfillEntries,
			'last_run' => $lastRun,
		];
	}

	/**
	 * @param list<string> $ruleIds
	 */
	public function enqueueEntry(FreshRSS_Entry $entry, array $ruleIds = [], string $source = 'reception'): bool {
		$data = $this->read();
		$item = [
			'id' => 'queue_' . bin2hex(random_bytes(6)),
			'type' => 'entry',
			'source' => $source,
			'rule_ids' => $this->normalizeRuleIds($ruleIds),
			'enqueued_at' => date(DATE_ATOM),
			'attempts' => 0,
			'next_attempt_at' => 0,
			'entry' => [
				'entry_id' => method_exists($entry, 'id') ? (int)$entry->id() : 0,
				'feed_id' => method_exists($entry, 'feedId') ? (int)$entry->feedId() : 0,
				'guid' => method_exists($entry, 'guid') ? trim((string)$entry->guid()) : '',
				'link' => method_exists($entry, 'link') ? trim((string)$entry->link(true)) : '',
				'title' => method_exists($entry, 'title') ? trim((string)$entry->title()) : '',
				'date' => method_exists($entry, 'date') ? (int)$entry->date(true) : time(),
			],
		];
		$dedupeKey = $this->dedupeKey($item);
		foreach ($data['items'] as $existingItem) {
			if (!is_array($existingItem)) {
				continue;
			}
			if (($existingItem['type'] ?? '') !== 'entry') {
				continue;
			}
			if ($this->dedupeKey($existingItem) === $dedupeKey) {
				return false;
			}
		}

		array_unshift($data['items'], $item);
		$data['items'] = array_slice(array_values(array_filter($data['items'], 'is_array')), 0, self::MAX_ITEMS);
		$data['version'] = max(0, (int)($data['version'] ?? 0)) + 1;
		$this->write($data);
		return true;
	}

	/**
	 * @param list<string> $ruleIds
	 * @return array<string,mixed>
	 */
	public function enqueueBackfillJob(array $ruleIds, int $lookbackDays, int $limit): array {
		$data = $this->read();
		$item = [
			'id' => 'backfill_' . bin2hex(random_bytes(6)),
			'type' => 'backfill',
			'enqueued_at' => date(DATE_ATOM),
			'rule_ids' => $this->normalizeRuleIds($ruleIds),
			'state' => [
				'lookback_days' => max(1, min(3650, $lookbackDays)),
				'limit' => max(1, min(1000, $limit)),
				'offset' => 0,
				'processed' => 0,
				'updated' => 0,
				'matched_tags' => 0,
				'aggregate_entries' => 0,
				'aggregate_entry_keys' => [],
				'aggregate_entry_attempts' => 0,
				'aggregate_requests' => 0,
				'concurrent_entries' => 0,
				'fallback_entries' => 0,
			],
		];

		array_unshift($data['items'], $item);
		$data['items'] = array_slice(array_values(array_filter($data['items'], 'is_array')), 0, self::MAX_ITEMS);
		$data['version'] = max(0, (int)($data['version'] ?? 0)) + 1;
		$this->write($data);
		return $item;
	}

	/**
	 * @param list<array<string,mixed>> $items
	 * @param array<string,mixed>|null $lastRun
	 */
	public function replaceItems(array $items, ?array $lastRun = null, ?int $expectedVersion = null): bool {
		$data = $this->read();
		if ($expectedVersion !== null && max(0, (int)($data['version'] ?? 0)) !== $expectedVersion) {
			return false;
		}
		$data['items'] = array_values(array_filter($items, 'is_array'));
		if ($lastRun !== null) {
			$data['last_run'] = $lastRun;
		}
		$data['version'] = max(0, (int)($data['version'] ?? 0)) + 1;
		$this->write($data);
		return true;
	}

	public function clear(bool $resetLastRun = false): void {
		$data = $this->read();
		$data['items'] = [];
		if ($resetLastRun) {
			$data['last_run'] = null;
		}
		$data['version'] = max(0, (int)($data['version'] ?? 0)) + 1;
		$this->write($data);
		$this->clearManualRun();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function manualRun(): array {
		$snapshot = $this->snapshot();
		$content = $this->extension->readUserDataFile(self::MANUAL_RUN_FILE);
		if (!is_string($content) || $content === '') {
			return $this->normalizeManualRun([], $snapshot);
		}

		$data = json_decode($content, true);
		if (!is_array($data)) {
			return $this->normalizeManualRun([], $snapshot);
		}

		return $this->normalizeManualRun($data, $snapshot);
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	public function saveManualRun(array $state): array {
		$normalized = $this->normalizeManualRun($state, $this->snapshot());
		$this->extension->writeUserDataFile(
			self::MANUAL_RUN_FILE,
			(string)json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
		return $normalized;
	}

	/**
	 * @param array<string,mixed> $snapshot
	 * @return array<string,mixed>
	 */
	public function startManualRun(array $snapshot): array {
		return $this->saveManualRun([
			'run_id' => 'manual_' . bin2hex(random_bytes(6)),
			'status' => $this->snapshotWorkTotal($snapshot) > 0 ? 'running' : 'completed',
			'started_at' => date(DATE_ATOM),
			'updated_at' => date(DATE_ATOM),
			'initial_total' => $this->snapshotWorkTotal($snapshot),
			'last_snapshot' => $snapshot,
			'processed_total' => 0,
			'progress_percent' => $this->snapshotWorkTotal($snapshot) > 0 ? 0 : 100,
			'error' => '',
		]);
	}

	public function clearManualRun(): void {
		$this->extension->deleteUserDataFile(self::MANUAL_RUN_FILE);
	}

	/**
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $snapshot
	 * @return array<string,mixed>
	 */
	private function normalizeManualRun(array $state, array $snapshot): array {
		$initialTotal = max(0, (int)($state['initial_total'] ?? 0));
		$currentTotal = $this->snapshotWorkTotal($snapshot);
		$status = (string)($state['status'] ?? 'idle');
		$processedTotal = max(0, $initialTotal - min($initialTotal, $currentTotal));
		$progressPercent = $initialTotal > 0
			? (int)max(0, min(100, round(($processedTotal / $initialTotal) * 100)))
			: ($currentTotal === 0 ? 100 : 0);

		return [
			'run_id' => trim((string)($state['run_id'] ?? '')),
			'status' => in_array($status, ['idle', 'running', 'completed', 'error'], true)
				? $status
				: 'idle',
			'started_at' => trim((string)($state['started_at'] ?? '')),
			'updated_at' => trim((string)($state['updated_at'] ?? '')),
			'initial_total' => $initialTotal,
			'last_snapshot' => $snapshot,
			'processed_total' => $processedTotal,
			'progress_percent' => $progressPercent,
			'error' => trim((string)($state['error'] ?? '')),
		];
	}

	/**
	 * @param array<string,mixed> $snapshot
	 */
	private function snapshotWorkTotal(array $snapshot): int {
		return max(0, (int)($snapshot['pending_entries'] ?? 0)) + max(0, (int)($snapshot['pending_backfill_entries'] ?? 0));
	}

	/**
	 * @return array{items:list<array<string,mixed>>,last_run:array<string,mixed>|null,version:int}
	 */
	private function read(): array {
		$content = $this->extension->readUserDataFile(self::QUEUE_FILE);
		if (!is_string($content) || $content === '') {
			return ['items' => [], 'last_run' => null, 'version' => 0];
		}

		$data = json_decode($content, true);
		if (!is_array($data)) {
			return ['items' => [], 'last_run' => null, 'version' => 0];
		}

		return [
			'items' => array_values(array_filter($data['items'] ?? [], 'is_array')),
			'last_run' => is_array($data['last_run'] ?? null) ? $data['last_run'] : null,
			'version' => max(0, (int)($data['version'] ?? 0)),
		];
	}

	/**
	 * @param array{items:list<array<string,mixed>>,last_run:array<string,mixed>|null} $data
	 */
	private function write(array $data): void {
		$this->extension->writeUserDataFile(
			self::QUEUE_FILE,
			(string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
	}

	/**
	 * @param list<string> $ruleIds
	 * @return list<string>
	 */
	private function normalizeRuleIds(array $ruleIds): array {
		$normalized = [];
		foreach ($ruleIds as $ruleId) {
			$ruleId = trim((string)$ruleId);
			if ($ruleId !== '') {
				$normalized[$ruleId] = $ruleId;
			}
		}
		return array_values($normalized);
	}

	/**
	 * @param array<string,mixed> $item
	 */
	private function dedupeKey(array $item): string {
		$entry = is_array($item['entry'] ?? null) ? $item['entry'] : [];
		$ruleIds = array_values(array_filter(array_map(static fn ($ruleId): string => trim((string)$ruleId), is_array($item['rule_ids'] ?? null) ? $item['rule_ids'] : [])));
		return hash('sha256', implode('|', [
			(string)($entry['guid'] ?? ''),
			(string)($entry['link'] ?? ''),
			(string)($entry['title'] ?? ''),
			(string)($entry['date'] ?? ''),
			(string)($entry['feed_id'] ?? ''),
			implode(',', $ruleIds),
		]));
	}
}

final class AutoLabelHttpClient {
	public function supportsConcurrent(): bool {
		return function_exists('curl_init') && function_exists('curl_multi_init');
	}

	/**
	 * @param array<string,string> $headers
	 * @return array{status:int,body:string,json:mixed}
	 */
	public function postJson(string $url, array $payload, array $headers, int $timeoutSeconds): array {
		$headers['Content-Type'] = 'application/json';
		$headers['Accept'] = 'application/json';
		$encoded = (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			if ($ch === false) {
				throw new RuntimeException('Failed to initialize HTTP client.');
			}

			curl_setopt_array($ch, [
				CURLOPT_POST => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
				CURLOPT_POSTFIELDS => $encoded,
				CURLOPT_TIMEOUT => $timeoutSeconds,
				CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_NOSIGNAL => true,
			]);
			if (defined('CURL_HTTP_VERSION_1_1')) {
				curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
			}

			$body = curl_exec($ch);
			if ($body === false) {
				$error = curl_error($ch);
				curl_close($ch);
				throw new RuntimeException('HTTP request failed: ' . $error);
			}

			$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_close($ch);
		} else {
			$formattedHeaders = [];
			foreach ($headers as $name => $value) {
				$formattedHeaders[] = "{$name}: {$value}";
			}

			$context = stream_context_create([
				'http' => [
					'method' => 'POST',
					'header' => implode("\r\n", $formattedHeaders),
					'content' => $encoded,
					'timeout' => $timeoutSeconds,
					'ignore_errors' => true,
				],
			]);

			$body = @file_get_contents($url, false, $context);
			if ($body === false) {
				throw new RuntimeException('HTTP request failed.');
			}

			$status = 200;
			$headersLine = $http_response_header[0] ?? '';
			if (preg_match('/\s(\d{3})\s/', (string)$headersLine, $matches) === 1) {
				$status = (int)$matches[1];
			}
		}

		return $this->normalizeResponse($status, $body);
	}

	/**
	 * @param list<array{id:string,url:string,payload:array<string,mixed>,headers:array<string,string>,timeout_seconds:int}> $requests
	 * @return array<string,array{ok:bool,status?:int,body?:string,json?:mixed,error?:string,transport?:string}>
	 */
	public function postJsonConcurrent(array $requests): array {
		if (!$this->supportsConcurrent()) {
			throw new RuntimeException('Concurrent batch execution requires the PHP curl extension.');
		}
		if (count($requests) === 0) {
			return [];
		}

		$multi = curl_multi_init();
		if ($multi === false) {
			throw new RuntimeException('Failed to initialize concurrent HTTP client.');
		}

		$handles = [];
		foreach ($requests as $request) {
			$requestId = trim((string)($request['id'] ?? ''));
			if ($requestId === '') {
				continue;
			}

			$ch = curl_init((string)$request['url']);
			if ($ch === false) {
				$handles[$requestId] = ['handle' => null, 'error' => 'Failed to initialize HTTP client handle.'];
				continue;
			}

			$headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
			$headers['Content-Type'] = 'application/json';
			$headers['Accept'] = 'application/json';
			$timeoutSeconds = max(1, (int)($request['timeout_seconds'] ?? 15));
			curl_setopt_array($ch, [
				CURLOPT_POST => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
				CURLOPT_POSTFIELDS => (string)json_encode($request['payload'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				CURLOPT_TIMEOUT => $timeoutSeconds,
				CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_NOSIGNAL => true,
			]);

			curl_multi_add_handle($multi, $ch);
			$handles[$requestId] = [
				'handle' => $ch,
				'error' => '',
				'request' => $request,
			];
		}

		try {
			$running = 0;
			do {
				do {
					$status = curl_multi_exec($multi, $running);
				} while (defined('CURLM_CALL_MULTI_PERFORM') && $status === CURLM_CALL_MULTI_PERFORM);

				if ($status !== CURLM_OK) {
					break;
				}
				if ($running > 0) {
					$selected = curl_multi_select($multi, 1.0);
					if ($selected === -1) {
						usleep(10000);
					}
				}
			} while ($running > 0);

			$results = [];
			foreach ($handles as $requestId => $handleInfo) {
				$ch = $handleInfo['handle'] ?? null;
				if (!is_resource($ch) && !(is_object($ch) && get_class($ch) === 'CurlHandle')) {
					$results[$requestId] = [
						'ok' => false,
						'error' => (string)($handleInfo['error'] ?? 'Failed to initialize HTTP client handle.'),
						'transport' => 'concurrent',
					];
					continue;
				}

				$body = curl_multi_getcontent($ch);
				$errno = curl_errno($ch);
				$error = curl_error($ch);
				$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
				curl_multi_remove_handle($multi, $ch);
				curl_close($ch);

				if ($errno !== 0) {
					$results[$requestId] = $this->retryConcurrentFallback(
						$handleInfo['request'] ?? null,
						'HTTP request failed: ' . $error
					);
					continue;
				}
				if ($status <= 0 && (!is_string($body) || trim($body) === '')) {
					$results[$requestId] = $this->retryConcurrentFallback(
						$handleInfo['request'] ?? null,
						'HTTP request failed: empty response from provider.'
					);
					continue;
				}

				try {
					$response = $this->normalizeResponse($status, is_string($body) ? $body : '');
					$results[$requestId] = [
						'ok' => true,
						'status' => $response['status'],
						'body' => $response['body'],
						'json' => $response['json'],
						'transport' => 'concurrent',
					];
				} catch (Throwable $throwable) {
					$results[$requestId] = [
						'ok' => false,
						'error' => $throwable->getMessage(),
						'status' => $status,
						'body' => is_string($body) ? $body : '',
						'transport' => 'concurrent',
					];
				}
			}

			return $results;
		} finally {
			curl_multi_close($multi);
		}
	}

	/**
	 * @param array<string,string> $headers
	 * @return list<string>
	 */
	private function formatHeaders(array $headers): array {
		$formatted = [];
		foreach ($headers as $name => $value) {
			$formatted[] = "{$name}: {$value}";
		}
		return $formatted;
	}

	/**
	 * @return array{status:int,body:string,json:mixed}
	 */
	private function normalizeResponse(int $status, string $body): array {
		$json = json_decode($body, true);
		if ($status >= 400) {
			$message = is_array($json) ? (string)json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $body;
			throw new RuntimeException("HTTP {$status}: {$message}");
		}

		return [
			'status' => $status,
			'body' => $body,
			'json' => $json,
		];
	}

	/**
	 * @param mixed $request
	 * @return array{ok:bool,status?:int,body?:string,json?:mixed,error?:string,transport?:string}
	 */
	private function retryConcurrentFallback($request, string $fallbackError): array {
		if (!is_array($request)) {
			return [
				'ok' => false,
				'error' => $fallbackError,
				'transport' => 'fallback_failed',
			];
		}

		try {
			$response = $this->postJson(
				(string)($request['url'] ?? ''),
				is_array($request['payload'] ?? null) ? $request['payload'] : [],
				is_array($request['headers'] ?? null) ? $request['headers'] : [],
				max(1, (int)($request['timeout_seconds'] ?? 15))
			);
			return [
				'ok' => true,
				'status' => $response['status'],
				'body' => $response['body'],
				'json' => $response['json'],
				'transport' => 'fallback_retry',
			];
		} catch (Throwable $throwable) {
			return [
				'ok' => false,
				'error' => $fallbackError . ' Retry failed: ' . $throwable->getMessage(),
				'transport' => 'fallback_failed',
			];
		}
	}
}

interface AutoLabelProviderInterface {
	/**
	 * @param array<string,mixed> $profile
	 * @return array{id:string,url:string,payload:array<string,mixed>,headers:array<string,string>,timeout_seconds:int}
	 */
	public function buildTextRequest(array $profile, string $systemPrompt, string $prompt, int $maxOutputTokens = 300): array;

	/**
	 * @param array{status:int,body:string,json:mixed} $response
	 */
	public function parseTextResponse(array $response): string;

	/**
	 * @param array<string,mixed> $profile
	 * @return array{id:string,url:string,payload:array<string,mixed>,headers:array<string,string>,timeout_seconds:int}
	 */
	public function buildSingleEmbeddingRequest(array $profile, string $text, ?string $instruction = null): array;

	/**
	 * @param array{status:int,body:string,json:mixed} $response
	 * @return list<float>
	 */
	public function parseSingleEmbeddingResponse(array $response): array;

	/**
	 * @param array<string,mixed> $profile
	 * @return array{match:bool,reason:string,confidence:float|null,raw:string}
	 */
	public function classify(array $profile, string $prompt): array;

	/**
	 * @param array<string,mixed> $profile
	 * @param list<string> $texts
	 * @return list<list<float>>
	 */
	public function embedTexts(array $profile, array $texts, ?string $instruction = null): array;
}

abstract class AutoLabelAbstractProvider implements AutoLabelProviderInterface {
	protected const CLASSIFIER_SYSTEM_PROMPT = 'You are a strict binary classifier. Return JSON only: {"match":true|false,"confidence":0..1,"reason":"short explanation"}.';

	/** @var AutoLabelHttpClient */
	protected $http;

	public function __construct(AutoLabelHttpClient $http) {
		$this->http = $http;
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	protected function baseUrl(array $profile, string $default): string {
		$base = trim((string)($profile['base_url'] ?? ''));
		return $base !== '' ? rtrim($base, '/') : rtrim($default, '/');
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	protected function timeout(array $profile): int {
		return max(3, min(AutoLabelSystemProfileRepository::MAX_TIMEOUT_SECONDS, (int)($profile['timeout_seconds'] ?? AutoLabelSystemProfileRepository::DEFAULT_TIMEOUT_SECONDS)));
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	protected function embeddingTimeout(array $profile): int {
		$timeout = $this->timeout($profile);
		return max($timeout, 60);
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	protected function apiKey(array $profile): string {
		return trim((string)($profile['api_key'] ?? ''));
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	protected function embeddingDimensions(array $profile): int {
		return max(0, (int)($profile['embedding_dimensions'] ?? 0));
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	protected function embeddingNumCtx(array $profile): int {
		return max(0, (int)($profile['embedding_num_ctx'] ?? 0));
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	protected function jsonModeEnabled(array $profile): bool {
		return !array_key_exists('json_mode', $profile) || (bool)$profile['json_mode'];
	}

	/**
	 * @param array<string,mixed> $profile
	 * @return array<string,mixed>
	 */
	protected function llmOptions(array $profile): array {
		$raw = trim((string)($profile['llm_options_json'] ?? ''));
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $profile
	 * @return array<string,mixed>
	 */
	protected function applyLlmOptions(array $payload, array $profile): array {
		$options = $this->llmOptions($profile);
		if (count($options) === 0) {
			return $payload;
		}

		return array_replace_recursive($payload, $options);
	}

	protected function appendPath(string $baseUrl, string $path): string {
		$baseUrl = rtrim($baseUrl, '/');
		if (substr($baseUrl, -3) === '/v1' && substr($path, 0, 4) === '/v1/') {
			return $baseUrl . substr($path, 3);
		}
		return $baseUrl . $path;
	}

	/**
	 * @param array<string,mixed> $profile
	 * @param list<string> $endpointSuffixes
	 */
	protected function endpointUrl(array $profile, string $defaultBase, string $defaultPath, array $endpointSuffixes): string {
		$url = $this->baseUrl($profile, $defaultBase);
		$path = rtrim(strtolower((string)(parse_url($url, PHP_URL_PATH) ?: '')), '/');
		foreach ($endpointSuffixes as $suffix) {
			if ($path === strtolower(rtrim($suffix, '/')) || str_ends_with($path, '/' . ltrim(strtolower(rtrim($suffix, '/')), '/'))) {
				return $url;
			}
		}

		return $this->appendPath($url, $defaultPath);
	}

	/**
	 * @return array{match:bool,reason:string,confidence:float|null,raw:string}
	 */
	protected function parseDecision(string $text): array {
		$raw = trim($text);
		$decoded = json_decode($raw, true);
		if (!is_array($decoded) && preg_match('/\{.*\}/s', $raw, $matches) === 1) {
			$decoded = json_decode($matches[0], true);
		}
		if (!is_array($decoded)) {
			return [
				'match' => false,
				'reason' => 'The model did not return valid JSON.',
				'confidence' => null,
				'raw' => $raw,
			];
		}

		$confidence = null;
		if (isset($decoded['confidence']) && is_numeric($decoded['confidence'])) {
			$confidence = max(0.0, min(1.0, (float)$decoded['confidence']));
		}

		return [
			'match' => (bool)($decoded['match'] ?? false),
			'reason' => trim((string)($decoded['reason'] ?? '')),
			'confidence' => $confidence,
			'raw' => $raw,
		];
	}

	/**
	 * @param list<string> $texts
	 * @return list<string>
	 */
	protected function applyInstructionPrefix(array $profile, array $texts, ?string $instruction): array {
		$instruction = trim((string)$instruction);
		if ($instruction === '') {
			$instruction = trim((string)($profile['default_instruction'] ?? ''));
		}
		if ($instruction === '') {
			return $texts;
		}

		return array_map(
			static fn (string $text): string => "Instruction: {$instruction}\n\nText:\n{$text}",
			$texts
		);
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	protected function thinkingMode(array $profile): string {
		$mode = is_string($profile['thinking_mode'] ?? null) ? trim((string)$profile['thinking_mode']) : '';
		return in_array($mode, AutoLabelSystemProfileRepository::THINKING_MODES, true)
			? $mode
			: AutoLabelSystemProfileRepository::DEFAULT_THINKING_MODE;
	}

	/**
	 * Appends provider-agnostic thinking control directives to a system prompt.
	 * Safe no-op for models that do not understand them.
	 *
	 * @param array<string,mixed> $profile
	 */
	protected function applyThinkingDirectiveToSystemPrompt(array $profile, string $systemPrompt): string {
		switch ($this->thinkingMode($profile)) {
			case 'disabled':
				return rtrim($systemPrompt) . "\n\nDo not output any reasoning or <think> blocks. Respond with the final JSON answer only. /no_think";
			case 'enabled':
				return rtrim($systemPrompt) . "\n\n/think";
			case 'auto':
			default:
				return $systemPrompt;
		}
	}

	/**
	 * Strips any <think>...</think> reasoning blocks that some models (e.g. qwen3, deepseek-r1)
	 * emit before their final answer. Works regardless of the thinking_mode setting so that
	 * downstream JSON parsing does not choke on mixed output.
	 */
	protected function stripThinkingContent(string $text): string {
		if ($text === '') {
			return $text;
		}
		// Remove complete <think>...</think> blocks (including any whitespace around them).
		$stripped = preg_replace('#\s*<think\b[^>]*>.*?</think>\s*#is', '', $text);
		if (!is_string($stripped)) {
			$stripped = $text;
		}
		// Handle orphaned openings: if a closing </think> exists without a matching open, drop everything up to it.
		if (stripos($stripped, '</think>') !== false && stripos($stripped, '<think') === false) {
			$pos = stripos($stripped, '</think>');
			$stripped = substr($stripped, $pos + strlen('</think>'));
		}
		return trim($stripped);
	}

	/**
	 * @param array<int,mixed> $responseOutput
	 */
	protected function extractOpenAIOutputText(array $responseOutput): string {
		$texts = [];
		foreach ($responseOutput as $item) {
			if (!is_array($item) || ($item['type'] ?? '') !== 'message') {
				continue;
			}
			$content = $item['content'] ?? [];
			if (!is_array($content)) {
				continue;
			}
			foreach ($content as $part) {
				if (is_array($part) && is_string($part['text'] ?? null)) {
					$texts[] = $part['text'];
				}
			}
		}

		return trim(implode("\n", $texts));
	}

	public function classify(array $profile, string $prompt): array {
		$request = $this->buildTextRequest($profile, self::CLASSIFIER_SYSTEM_PROMPT, $prompt);
		$response = $this->http->postJson(
			(string)$request['url'],
			$request['payload'],
			$request['headers'],
			(int)$request['timeout_seconds']
		);

		return $this->parseDecision($this->parseTextResponse($response));
	}

	public function embedTexts(array $profile, array $texts, ?string $instruction = null): array {
		$embeddings = [];
		foreach ($texts as $text) {
			$request = $this->buildSingleEmbeddingRequest($profile, (string)$text, $instruction);
			$response = $this->http->postJson(
				(string)$request['url'],
				$request['payload'],
				$request['headers'],
				(int)$request['timeout_seconds']
			);
			$embeddings[] = $this->parseSingleEmbeddingResponse($response);
		}

		return $embeddings;
	}
}

final class AutoLabelOpenAIProvider extends AutoLabelAbstractProvider {
	public function buildTextRequest(array $profile, string $systemPrompt, string $prompt, int $maxOutputTokens = 300): array {
		$apiKey = $this->apiKey($profile);
		if ($apiKey === '') {
			throw new RuntimeException('OpenAI profile requires an API key.');
		}

		$systemPrompt = $this->applyThinkingDirectiveToSystemPrompt($profile, $systemPrompt);
		$url = $this->openAiTextEndpointUrl($profile);
		if (!$this->isResponsesEndpoint($url)) {
			$payload = [
				'model' => (string)$profile['model'],
				'messages' => [
					[
						'role' => 'system',
						'content' => $systemPrompt,
					],
					[
						'role' => 'user',
						'content' => $prompt,
					],
				],
				'max_tokens' => max(300, min(12000, $maxOutputTokens)),
			];
			if ($this->jsonModeEnabled($profile)) {
				$payload['response_format'] = ['type' => 'json_object'];
			}

			return [
				'id' => '',
				'url' => $url,
				'payload' => $this->applyLlmOptions($payload, $profile),
				'headers' => [
					'Authorization' => 'Bearer ' . $apiKey,
				],
				'timeout_seconds' => $this->timeout($profile),
			];
		}

		$payload = [
			'model' => (string)$profile['model'],
			'instructions' => $systemPrompt,
			'input' => [[
				'role' => 'user',
				'content' => [[
					'type' => 'input_text',
					'text' => $prompt,
				]],
			]],
			'max_output_tokens' => max(300, min(12000, $maxOutputTokens)),
		];
		if ($this->jsonModeEnabled($profile)) {
			$payload['text'] = ['format' => ['type' => 'json_object']];
		}

		return [
			'id' => '',
			'url' => $url,
			'payload' => $this->applyLlmOptions($payload, $profile),
			'headers' => [
				'Authorization' => 'Bearer ' . $apiKey,
			],
			'timeout_seconds' => $this->timeout($profile),
		];
	}

	public function parseTextResponse(array $response): string {
		$json = is_array($response['json']) ? $response['json'] : [];
		$choices = is_array($json['choices'] ?? null) ? $json['choices'] : [];
		$message = is_array($choices[0]['message'] ?? null) ? $choices[0]['message'] : [];
		if (is_string($message['content'] ?? null)) {
			return $this->stripThinkingContent($message['content']);
		}
		if (is_array($message['content'] ?? null)) {
			$texts = [];
			foreach ($message['content'] as $part) {
				if (is_array($part) && is_string($part['text'] ?? null)) {
					$texts[] = $part['text'];
				}
			}
			if ($texts !== []) {
				return $this->stripThinkingContent(trim(implode("\n", $texts)));
			}
		}

		$text = is_string($json['output_text'] ?? null)
			? $json['output_text']
			: $this->extractOpenAIOutputText(is_array($json['output'] ?? null) ? $json['output'] : []);
		return $this->stripThinkingContent($text);
	}

	private function openAiTextEndpointUrl(array $profile): string {
		$url = $this->baseUrl($profile, 'https://api.openai.com');
		$path = rtrim(strtolower((string)(parse_url($url, PHP_URL_PATH) ?: '')), '/');
		if (str_ends_with($path, '/responses') || str_ends_with($path, '/chat/completions')) {
			return $url;
		}

		$host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
		$defaultPath = $host === 'api.openai.com' ? '/v1/responses' : '/chat/completions';
		return $this->appendPath($url, $defaultPath);
	}

	private function isResponsesEndpoint(string $url): bool {
		$path = rtrim(strtolower((string)(parse_url($url, PHP_URL_PATH) ?: '')), '/');
		return str_ends_with($path, '/responses');
	}

	public function buildSingleEmbeddingRequest(array $profile, string $text, ?string $instruction = null): array {
		$apiKey = $this->apiKey($profile);
		if ($apiKey === '') {
			throw new RuntimeException('OpenAI profile requires an API key.');
		}

		$preparedTexts = $this->applyInstructionPrefix($profile, [$text], $instruction);
		$payload = [
			'model' => (string)$profile['model'],
			'input' => $preparedTexts,
		];
		$dimensions = $this->embeddingDimensions($profile);
		if ($dimensions > 0) {
			$payload['dimensions'] = $dimensions;
		}

		return [
			'id' => '',
			'url' => $this->endpointUrl($profile, 'https://api.openai.com', '/v1/embeddings', ['/v1/embeddings', '/embeddings']),
			'payload' => $payload,
			'headers' => [
				'Authorization' => 'Bearer ' . $apiKey,
			],
			'timeout_seconds' => $this->embeddingTimeout($profile),
		];
	}

	public function parseSingleEmbeddingResponse(array $response): array {
		$json = is_array($response['json']) ? $response['json'] : [];
		$data = is_array($json['data'] ?? null) ? $json['data'] : [];
		if (!isset($data[0]['embedding']) || !is_array($data[0]['embedding'])) {
			throw new RuntimeException('OpenAI embedding response was missing values.');
		}

		return array_values(array_map(static fn ($value): float => (float)$value, $data[0]['embedding']));
	}

	public function embedTexts(array $profile, array $texts, ?string $instruction = null): array {
		$apiKey = $this->apiKey($profile);
		if ($apiKey === '') {
			throw new RuntimeException('OpenAI profile requires an API key.');
		}

		$preparedTexts = $this->applyInstructionPrefix($profile, $texts, $instruction);
		$payload = [
			'model' => (string)$profile['model'],
			'input' => $preparedTexts,
		];
		$dimensions = $this->embeddingDimensions($profile);
		if ($dimensions > 0) {
			$payload['dimensions'] = $dimensions;
		}
		$response = $this->http->postJson(
			$this->endpointUrl($profile, 'https://api.openai.com', '/v1/embeddings', ['/v1/embeddings', '/embeddings']),
			$payload,
			['Authorization' => 'Bearer ' . $apiKey],
			$this->embeddingTimeout($profile)
		);

		$json = is_array($response['json']) ? $response['json'] : [];
		$data = is_array($json['data'] ?? null) ? $json['data'] : [];
		$embeddings = [];
		foreach ($data as $item) {
			if (is_array($item['embedding'] ?? null)) {
				$embeddings[] = array_values(array_map(static fn ($value): float => (float)$value, $item['embedding']));
			}
		}

		return $embeddings;
	}
}

final class AutoLabelAnthropicProvider extends AutoLabelAbstractProvider {
	public function buildTextRequest(array $profile, string $systemPrompt, string $prompt, int $maxOutputTokens = 300): array {
		$apiKey = $this->apiKey($profile);
		if ($apiKey === '') {
			throw new RuntimeException('Anthropic profile requires an API key.');
		}

		$systemPrompt = $this->applyThinkingDirectiveToSystemPrompt($profile, $systemPrompt);
		$payload = [
			'model' => (string)$profile['model'],
			'max_tokens' => max(300, min(12000, $maxOutputTokens)),
			'system' => $systemPrompt,
			'messages' => [[
				'role' => 'user',
				'content' => $prompt,
			]],
		];

		return [
			'id' => '',
			'url' => $this->endpointUrl($profile, 'https://api.anthropic.com', '/v1/messages', ['/v1/messages', '/messages']),
			'payload' => $this->applyLlmOptions($payload, $profile),
			'headers' => [
				'x-api-key' => $apiKey,
				'anthropic-version' => '2023-06-01',
			],
			'timeout_seconds' => $this->timeout($profile),
		];
	}

	public function parseTextResponse(array $response): string {
		$json = is_array($response['json']) ? $response['json'] : [];
		$content = is_array($json['content'] ?? null) ? $json['content'] : [];
		$text = '';
		foreach ($content as $block) {
			if (is_array($block) && is_string($block['text'] ?? null)) {
				$text .= $block['text'];
			}
		}
		return $this->stripThinkingContent($text);
	}

	public function buildSingleEmbeddingRequest(array $profile, string $text, ?string $instruction = null): array {
		throw new RuntimeException('This provider does not support embeddings.');
	}

	public function parseSingleEmbeddingResponse(array $response): array {
		throw new RuntimeException('This provider does not support embeddings.');
	}
}

final class AutoLabelGeminiProvider extends AutoLabelAbstractProvider {
	public function buildTextRequest(array $profile, string $systemPrompt, string $prompt, int $maxOutputTokens = 300): array {
		$apiKey = $this->apiKey($profile);
		if ($apiKey === '') {
			throw new RuntimeException('Gemini profile requires an API key.');
		}

		$systemPrompt = $this->applyThinkingDirectiveToSystemPrompt($profile, $systemPrompt);

		$model = rawurlencode((string)$profile['model']);
		$payload = [
			'systemInstruction' => [
				'parts' => [[
					'text' => $systemPrompt,
				]],
			],
			'contents' => [[
				'role' => 'user',
				'parts' => [[
					'text' => $prompt,
				]],
			]],
			'generationConfig' => [
				'maxOutputTokens' => max(300, min(12000, $maxOutputTokens)),
			],
		];
		if ($this->jsonModeEnabled($profile)) {
			$payload['generationConfig']['responseMimeType'] = 'application/json';
		}

		return [
			'id' => '',
			'url' => $this->baseUrl($profile, 'https://generativelanguage.googleapis.com') . "/v1beta/models/{$model}:generateContent?key=" . rawurlencode($apiKey),
			'payload' => $this->applyLlmOptions($payload, $profile),
			'headers' => [],
			'timeout_seconds' => $this->timeout($profile),
		];
	}

	public function parseTextResponse(array $response): string {
		$json = is_array($response['json']) ? $response['json'] : [];
		return $this->stripThinkingContent((string)($json['candidates'][0]['content']['parts'][0]['text'] ?? ''));
	}

	public function buildSingleEmbeddingRequest(array $profile, string $text, ?string $instruction = null): array {
		$apiKey = $this->apiKey($profile);
		if ($apiKey === '') {
			throw new RuntimeException('Gemini profile requires an API key.');
		}

		$model = rawurlencode((string)$profile['model']);
		$preparedTexts = $this->applyInstructionPrefix($profile, [$text], $instruction);
		$payload = [
			'content' => [
				'parts' => [[
					'text' => $preparedTexts[0],
				]],
			],
			'taskType' => 'SEMANTIC_SIMILARITY',
		];
		$dimensions = $this->embeddingDimensions($profile);
		if ($dimensions > 0) {
			$payload['outputDimensionality'] = $dimensions;
		}

		return [
			'id' => '',
			'url' => $this->baseUrl($profile, 'https://generativelanguage.googleapis.com') . "/v1beta/models/{$model}:embedContent?key=" . rawurlencode($apiKey),
			'payload' => $payload,
			'headers' => [],
			'timeout_seconds' => $this->embeddingTimeout($profile),
		];
	}

	public function parseSingleEmbeddingResponse(array $response): array {
		$json = is_array($response['json']) ? $response['json'] : [];
		$values = $json['embedding']['values'] ?? null;
		if (!is_array($values)) {
			throw new RuntimeException('Gemini embedding response was missing values.');
		}
		return array_values(array_map(static fn ($value): float => (float)$value, $values));
	}

	public function embedTexts(array $profile, array $texts, ?string $instruction = null): array {
		$apiKey = $this->apiKey($profile);
		if ($apiKey === '') {
			throw new RuntimeException('Gemini profile requires an API key.');
		}

		$model = rawurlencode((string)$profile['model']);
		$baseUrl = $this->baseUrl($profile, 'https://generativelanguage.googleapis.com');
		$preparedTexts = $this->applyInstructionPrefix($profile, $texts, $instruction);
		$embeddings = [];
		$dimensions = $this->embeddingDimensions($profile);
		if (count($preparedTexts) > 1) {
			$requests = [];
			foreach ($preparedTexts as $text) {
				$request = [
					'model' => 'models/' . (string)$profile['model'],
					'content' => [
						'parts' => [[
							'text' => $text,
						]],
					],
					'taskType' => 'SEMANTIC_SIMILARITY',
				];
				if ($dimensions > 0) {
					$request['outputDimensionality'] = $dimensions;
				}
				$requests[] = $request;
			}

			$response = $this->http->postJson(
				$baseUrl . "/v1beta/models/{$model}:batchEmbedContents?key=" . rawurlencode($apiKey),
				['requests' => $requests],
				[],
				$this->embeddingTimeout($profile)
			);

			$json = is_array($response['json']) ? $response['json'] : [];
			$batchEmbeddings = is_array($json['embeddings'] ?? null) ? $json['embeddings'] : [];
			foreach ($batchEmbeddings as $item) {
				$values = $item['values'] ?? null;
				if (!is_array($values)) {
					throw new RuntimeException('Gemini batch embedding response was missing values.');
				}
				$embeddings[] = array_values(array_map(static fn ($value): float => (float)$value, $values));
			}
			return $embeddings;
		}

		return parent::embedTexts($profile, $texts, $instruction);
	}
}

final class AutoLabelOllamaProvider extends AutoLabelAbstractProvider {
	public function buildTextRequest(array $profile, string $systemPrompt, string $prompt, int $maxOutputTokens = 300): array {
		$headers = [];
		if ($this->apiKey($profile) !== '') {
			$headers['Authorization'] = 'Bearer ' . $this->apiKey($profile);
		}

		$thinkingMode = $this->thinkingMode($profile);
		$systemPrompt = $this->applyThinkingDirectiveToSystemPrompt($profile, $systemPrompt);

		$payload = [
			'model' => (string)$profile['model'],
			'messages' => [
				[
					'role' => 'system',
					'content' => $systemPrompt,
				],
				[
					'role' => 'user',
					'content' => $prompt,
				],
			],
			'stream' => false,
			'options' => [
				'num_predict' => max(300, min(12000, $maxOutputTokens)),
			],
		];
		if ($this->jsonModeEnabled($profile)) {
			$payload['format'] = 'json';
		}
		if ($thinkingMode === 'disabled') {
			$payload['think'] = false;
		} elseif ($thinkingMode === 'enabled') {
			$payload['think'] = true;
		}

		return [
			'id' => '',
			'url' => $this->endpointUrl($profile, 'http://127.0.0.1:11434', '/api/chat', ['/api/chat']),
			'payload' => $this->applyLlmOptions($payload, $profile),
			'headers' => $headers,
			'timeout_seconds' => $this->timeout($profile),
		];
	}

	public function parseTextResponse(array $response): string {
		$json = is_array($response['json']) ? $response['json'] : [];
		return $this->stripThinkingContent((string)($json['message']['content'] ?? ''));
	}

	public function buildSingleEmbeddingRequest(array $profile, string $text, ?string $instruction = null): array {
		$headers = [];
		if ($this->apiKey($profile) !== '') {
			$headers['Authorization'] = 'Bearer ' . $this->apiKey($profile);
		}

		$preparedTexts = $this->applyInstructionPrefix($profile, [$text], $instruction);
		$payload = [
			'model' => (string)$profile['model'],
			'input' => array_values($preparedTexts),
			'truncate' => true,
		];
		$dimensions = $this->embeddingDimensions($profile);
		if ($dimensions > 0) {
			$payload['dimensions'] = $dimensions;
		}
		$numCtx = $this->embeddingNumCtx($profile);
		if ($numCtx > 0) {
			$payload['options'] = ['num_ctx' => $numCtx];
		}

		return [
			'id' => '',
			'url' => $this->endpointUrl($profile, 'http://127.0.0.1:11434', '/api/embed', ['/api/embed']),
			'payload' => $payload,
			'headers' => $headers,
			'timeout_seconds' => $this->embeddingTimeout($profile),
		];
	}

	public function parseSingleEmbeddingResponse(array $response): array {
		$json = is_array($response['json']) ? $response['json'] : [];
		if (is_array($json['embedding'] ?? null)) {
			return array_values(array_map(static fn ($value): float => (float)$value, $json['embedding']));
		}
		if (is_array($json['data']['embedding'] ?? null)) {
			return array_values(array_map(static fn ($value): float => (float)$value, $json['data']['embedding']));
		}
		if (is_array($json['data'][0]['embedding'] ?? null)) {
			return array_values(array_map(static fn ($value): float => (float)$value, $json['data'][0]['embedding']));
		}
		if (is_array($json['embeddings'] ?? null)) {
			$embeddings = $json['embeddings'];
			if (isset($embeddings[0]) && is_array($embeddings[0])) {
				if (is_array($embeddings[0]['embedding'] ?? null)) {
					return array_values(array_map(static fn ($value): float => (float)$value, $embeddings[0]['embedding']));
				}
				return array_values(array_map(static fn ($value): float => (float)$value, $embeddings[0]));
			}
			if (isset($embeddings[0]) && is_numeric($embeddings[0])) {
				return array_values(array_map(static fn ($value): float => (float)$value, $embeddings));
			}
		}
		$bodySnippet = trim((string)($response['body'] ?? ''));
		if (mb_strlen($bodySnippet, 'UTF-8') > 400) {
			$bodySnippet = mb_substr($bodySnippet, 0, 400, 'UTF-8') . '…';
		}
		throw new RuntimeException('Ollama embedding response was missing values. Response: ' . ($bodySnippet !== '' ? $bodySnippet : '[empty body]'));
	}

	public function embedTexts(array $profile, array $texts, ?string $instruction = null): array {
		$request = $this->buildSingleEmbeddingRequest($profile, '', $instruction);
		$preparedTexts = $this->applyInstructionPrefix($profile, $texts, $instruction);
		$request['payload']['input'] = array_values($preparedTexts);
		$response = $this->http->postJson(
			(string)$request['url'],
			$request['payload'],
			$request['headers'],
			(int)$request['timeout_seconds']
		);

		$json = is_array($response['json']) ? $response['json'] : [];
		$embeddings = [];
		if (is_array($json['embeddings'] ?? null)) {
			if (isset($json['embeddings'][0]) && is_numeric($json['embeddings'][0])) {
				$embeddings = [$json['embeddings']];
			} else {
				$embeddings = $json['embeddings'];
			}
		} elseif (is_array($json['embedding'] ?? null)) {
			$embeddings = [$json['embedding']];
		}
		$result = [];
		foreach ($embeddings as $embedding) {
			if (is_array($embedding)) {
				$result[] = array_values(array_map(static fn ($value): float => (float)$value, $embedding));
			}
		}
		return $result;
	}
}

final class AutoLabelProviderFactory {
	/** @var AutoLabelHttpClient */
	private $http;

	public function __construct(AutoLabelHttpClient $http) {
		$this->http = $http;
	}

	public function create(string $provider): AutoLabelProviderInterface {
		switch ($provider) {
			case 'openai':
				return new AutoLabelOpenAIProvider($this->http);
			case 'anthropic':
				return new AutoLabelAnthropicProvider($this->http);
			case 'gemini':
				return new AutoLabelGeminiProvider($this->http);
			case 'ollama':
				return new AutoLabelOllamaProvider($this->http);
			default:
				throw new RuntimeException('Unsupported provider: ' . $provider);
		}
	}
}

final class AutoLabelEngine {
	/** @var array<string,list<float>> */
	private array $entryEmbeddingMemo = [];
	private ?int $timeoutCapSeconds = null;
	/** @var AutoLabelHttpClient */
	private $http;
	/** @var AutoLabelSystemProfileRepository */
	private $profiles;
	/** @var AutoLabelUserRuleRepository */
	private $rules;
	/** @var AutoLabelProfileCapabilityResolver */
	private $capabilities;
	/** @var AutoLabelEntryTextExtractor */
	private $extractor;
	/** @var AutoLabelEmbeddingCacheStore */
	private $cache;
	/** @var AutoLabelDiagnosticsStore */
	private $diagnostics;
	/** @var AutoLabelProviderFactory */
	private $providers;

	public function __construct(
		AutoLabelHttpClient $http,
		AutoLabelSystemProfileRepository $profiles,
		AutoLabelUserRuleRepository $rules,
		AutoLabelProfileCapabilityResolver $capabilities,
		AutoLabelEntryTextExtractor $extractor,
		AutoLabelEmbeddingCacheStore $cache,
		AutoLabelDiagnosticsStore $diagnostics,
		AutoLabelProviderFactory $providers
	) {
		$this->http = $http;
		$this->profiles = $profiles;
		$this->rules = $rules;
		$this->capabilities = $capabilities;
		$this->extractor = $extractor;
		$this->cache = $cache;
		$this->diagnostics = $diagnostics;
		$this->providers = $providers;
	}

	public function setTimeoutCap(?int $seconds): void {
		$this->timeoutCapSeconds = $seconds !== null ? max(1, $seconds) : null;
	}

	public function supportsConcurrentWindow(): bool {
		return $this->http->supportsConcurrent();
	}

	/**
	 * @param array<string,mixed> $profile
	 * @param list<array{task_id:string,entry:FreshRSS_Entry,rules:list<array<string,mixed>>}> $tasks
	 * @return array<string,array{tags:list<string>,mark_read:bool,results:list<array<string,mixed>>,context:array<string,string>,failed_rule_ids:list<string>,transport:string}>
	 */
	public function runProfileBatch(array $profile, array $tasks): array {
		if (count($tasks) === 0) {
			return [];
		}

		$effectiveProfile = $this->effectiveProfile($profile);
		if (($effectiveProfile['profile_mode'] ?? 'llm') === 'embedding' && !$this->supportsConcurrentWindow()) {
			throw new RuntimeException('Embedding batch execution requires the PHP curl extension with curl_multi support.');
		}
		$contextsByTask = [];
		foreach ($tasks as $task) {
			$maxChars = (int)($effectiveProfile['content_max_chars'] ?? AutoLabelSystemProfileRepository::DEFAULT_CONTENT_MAX_CHARS);
			$contextsByTask[$task['task_id']] = $this->extractor->extractContext($task['entry'], $maxChars);
		}

		return ($effectiveProfile['profile_mode'] ?? 'llm') === 'embedding'
			? $this->runEmbeddingProfileBatch($effectiveProfile, $tasks, $contextsByTask)
			: $this->runLlmProfileBatch($effectiveProfile, $tasks, $contextsByTask);
	}

	/**
	 * @param array<string,mixed> $profile
	 * @param list<array{task_id:string,entry:FreshRSS_Entry,rules:list<array<string,mixed>>}> $tasks
	 * @param array<string,array<string,string>> $contextsByTask
	 * @return array<string,array{tags:list<string>,mark_read:bool,results:list<array<string,mixed>>,context:array<string,string>,failed_rule_ids:list<string>,transport:string}>
	 */
	private function runLlmProfileBatch(array $profile, array $tasks, array $contextsByTask): array {
		$provider = $this->providers->create((string)$profile['provider']);
		$resultsByTask = [];
		foreach ($tasks as $task) {
			$taskId = (string)$task['task_id'];
			$resultsByTask[$taskId] = [
				'tags' => [],
				'mark_read' => false,
				'results' => [],
				'context' => $this->diagnosticContext($contextsByTask[$taskId]),
				'failed_rule_ids' => [],
				'transport' => 'aggregate',
			];
		}

		try {
			$request = $provider->buildTextRequest(
				$profile,
				'You are a strict article-rule matrix classifier. Return JSON only in the form {"results":[{"task_id":"...","rule_id":"...","match":true|false,"confidence":0..1,"reason":"short explanation"}]}. Include every requested task_id and rule_id pair exactly once.',
				$this->buildLlmMatrixPrompt($tasks, $contextsByTask),
				$this->llmMatrixMaxOutputTokens($tasks)
			);
			$response = $this->http->postJson(
				(string)$request['url'],
				$request['payload'],
				$request['headers'],
				(int)$request['timeout_seconds']
			);
			$decisionMap = $this->parseLlmMatrixDecisions($provider->parseTextResponse($response), count($tasks) === 1 ? (string)$tasks[0]['task_id'] : null);
		} catch (Throwable $throwable) {
			foreach ($tasks as $task) {
				$taskId = (string)$task['task_id'];
				foreach ($task['rules'] as $rule) {
					$resultsByTask[$taskId]['results'][] = [
						'rule_id' => $rule['id'],
						'rule_name' => $rule['name'],
						'target_tags' => $rule['target_tags'],
						'mode' => 'llm',
						'matched' => false,
						'status' => 'error',
						'reason' => $throwable->getMessage(),
					];
					$resultsByTask[$taskId]['failed_rule_ids'][] = (string)$rule['id'];
				}
				$resultsByTask[$taskId]['failed_rule_ids'] = array_values(array_unique($resultsByTask[$taskId]['failed_rule_ids']));
			}
			return $resultsByTask;
		}

		foreach ($tasks as $task) {
			$taskId = (string)$task['task_id'];
			foreach ($task['rules'] as $rule) {
				$ruleId = (string)$rule['id'];
				$decision = $decisionMap[$taskId][$ruleId] ?? null;
				if (!is_array($decision)) {
					$resultsByTask[$taskId]['results'][] = [
						'rule_id' => $ruleId,
						'rule_name' => $rule['name'],
						'target_tags' => $rule['target_tags'],
						'mode' => 'llm',
						'matched' => false,
						'status' => 'error',
						'reason' => 'The model did not return a decision for this article and rule.',
					];
					$resultsByTask[$taskId]['failed_rule_ids'][] = $ruleId;
					continue;
				}

				$matched = !empty($decision['match']);
				$resultsByTask[$taskId]['results'][] = [
					'rule_id' => $ruleId,
					'rule_name' => $rule['name'],
					'target_tags' => $rule['target_tags'],
					'mode' => 'llm',
					'matched' => $matched,
					'status' => 'ok',
					'reason' => trim((string)($decision['reason'] ?? '')),
					'confidence' => isset($decision['confidence']) && is_numeric($decision['confidence'])
						? max(0.0, min(1.0, (float)$decision['confidence']))
						: null,
				];
				if ($matched) {
					if (!empty($rule['mark_read_on_match'])) {
						$resultsByTask[$taskId]['mark_read'] = true;
					}
					foreach ($rule['target_tags'] as $targetTag) {
						$resultsByTask[$taskId]['tags'][] = (string)$targetTag;
					}
				}
			}

			$resultsByTask[$taskId]['tags'] = array_values(array_unique($resultsByTask[$taskId]['tags']));
			$resultsByTask[$taskId]['failed_rule_ids'] = array_values(array_unique($resultsByTask[$taskId]['failed_rule_ids']));
		}

		return $resultsByTask;
	}

	/**
	 * @param array<string,mixed> $profile
	 * @param list<array{task_id:string,entry:FreshRSS_Entry,rules:list<array<string,mixed>>}> $tasks
	 * @param array<string,array<string,string>> $contextsByTask
	 * @return array<string,array{tags:list<string>,mark_read:bool,results:list<array<string,mixed>>,context:array<string,string>,failed_rule_ids:list<string>,transport:string}>
	 */
	private function runEmbeddingProfileBatch(array $profile, array $tasks, array $contextsByTask): array {
		$provider = $this->providers->create((string)$profile['provider']);
		$aggregates = [];
		$taskRulesByInstruction = [];
		$anchorsByInstruction = [];

		foreach ($tasks as $task) {
			$taskId = (string)$task['task_id'];
			$aggregates[$taskId] = [
				'tags' => [],
				'mark_read' => false,
				'results' => [],
				'context' => $this->diagnosticContext($contextsByTask[$taskId]),
				'failed_rule_ids' => [],
				'transport' => 'concurrent',
			];

			foreach ($task['rules'] as $rule) {
				$instruction = $this->effectiveInstruction($profile, $rule);
				if (!isset($taskRulesByInstruction[$instruction])) {
					$taskRulesByInstruction[$instruction] = [];
				}
				if (!isset($taskRulesByInstruction[$instruction][$taskId])) {
					$taskRulesByInstruction[$instruction][$taskId] = [];
				}
				$taskRulesByInstruction[$instruction][$taskId][] = $rule;
				foreach ($rule['embedding_anchor_texts'] as $anchorText) {
					$anchorsByInstruction[$instruction][(string)$anchorText] = (string)$anchorText;
				}
			}
		}

		$anchorVectorsByInstruction = [];
		foreach ($anchorsByInstruction as $instruction => $anchors) {
			$anchorVectorsByInstruction[$instruction] = [];
			$uncached = [];
			foreach ($anchors as $anchorText) {
				$cacheKey = $this->anchorCacheKey($profile, (string)$instruction, (string)$anchorText);
				$cachedVector = $this->cache->get($cacheKey);
				if ($cachedVector !== null) {
					$anchorVectorsByInstruction[$instruction][$anchorText] = $cachedVector;
				} else {
					$uncached[] = (string)$anchorText;
				}
			}

			if (count($uncached) > 0) {
				$uncachedVectors = $provider->embedTexts($profile, $uncached, (string)$instruction);
				foreach ($uncached as $index => $anchorText) {
					if (!isset($uncachedVectors[$index])) {
						throw new RuntimeException('Some anchor embeddings were not returned.');
					}
					$cacheKey = $this->anchorCacheKey($profile, (string)$instruction, (string)$anchorText);
					$this->cache->set($cacheKey, $uncachedVectors[$index]);
					$anchorVectorsByInstruction[$instruction][$anchorText] = $uncachedVectors[$index];
				}
			}
		}

		foreach ($taskRulesByInstruction as $instruction => $rulesByTask) {
			$requests = [];
			foreach ($rulesByTask as $taskId => $groupRules) {
				$request = $provider->buildSingleEmbeddingRequest(
					$profile,
					(string)($contextsByTask[$taskId]['embedding_text'] ?? $contextsByTask[$taskId]['text'] ?? ''),
					(string)$instruction
				);
				$request['id'] = (string)$taskId;
				$requests[] = $request;
			}

			$responses = $this->http->postJsonConcurrent($requests);
			foreach ($rulesByTask as $taskId => $groupRules) {
				$responseInfo = $responses[$taskId] ?? ['ok' => false, 'error' => 'No response was returned for this task.'];
				$aggregates[$taskId]['transport'] = (string)($responseInfo['transport'] ?? 'concurrent');
				if (!($responseInfo['ok'] ?? false)) {
					foreach ($groupRules as $rule) {
						$aggregates[$taskId]['results'][] = [
							'rule_id' => $rule['id'],
							'rule_name' => $rule['name'],
							'target_tags' => $rule['target_tags'],
							'mode' => 'embedding',
							'matched' => false,
							'status' => 'error',
							'reason' => (string)($responseInfo['error'] ?? 'Request failed.'),
						];
						$aggregates[$taskId]['failed_rule_ids'][] = (string)$rule['id'];
					}
					continue;
				}

				try {
					$entryVector = $provider->parseSingleEmbeddingResponse([
						'status' => (int)($responseInfo['status'] ?? 200),
						'body' => (string)($responseInfo['body'] ?? ''),
						'json' => $responseInfo['json'] ?? null,
					]);
				} catch (Throwable $throwable) {
					foreach ($groupRules as $rule) {
						$aggregates[$taskId]['results'][] = [
							'rule_id' => $rule['id'],
							'rule_name' => $rule['name'],
							'target_tags' => $rule['target_tags'],
							'mode' => 'embedding',
							'matched' => false,
							'status' => 'error',
							'reason' => $throwable->getMessage(),
						];
						$aggregates[$taskId]['failed_rule_ids'][] = (string)$rule['id'];
					}
					continue;
				}

				foreach ($groupRules as $rule) {
					$threshold = (float)$rule['embedding_threshold'];
					$bestSimilarity = -1.0;
					$bestAnchor = '';
					foreach ($rule['embedding_anchor_texts'] as $anchorText) {
						$anchorVector = $anchorVectorsByInstruction[$instruction][(string)$anchorText] ?? null;
						if (!is_array($anchorVector)) {
							continue;
						}
						$similarity = $this->cosineSimilarity($entryVector, $anchorVector);
						if ($similarity > $bestSimilarity) {
							$bestSimilarity = $similarity;
							$bestAnchor = (string)$anchorText;
						}
					}

					$matched = $bestSimilarity >= $threshold;
					$aggregates[$taskId]['results'][] = [
						'rule_id' => $rule['id'],
						'rule_name' => $rule['name'],
						'target_tags' => $rule['target_tags'],
						'mode' => 'embedding',
						'matched' => $matched,
						'status' => 'ok',
						'reason' => $bestAnchor === '' ? '' : 'Best anchor: ' . $bestAnchor,
						'confidence' => $bestSimilarity < 0 ? null : round($bestSimilarity, 4),
						'threshold' => $threshold,
					];
					if ($matched) {
						if (!empty($rule['mark_read_on_match'])) {
							$aggregates[$taskId]['mark_read'] = true;
						}
						foreach ($rule['target_tags'] as $targetTag) {
							$aggregates[$taskId]['tags'][] = (string)$targetTag;
						}
					}
				}
			}
		}

		foreach ($aggregates as $taskId => $aggregate) {
			$aggregates[$taskId]['tags'] = array_values(array_unique($aggregate['tags']));
			$aggregates[$taskId]['failed_rule_ids'] = array_values(array_unique($aggregate['failed_rule_ids']));
		}

		return $aggregates;
	}

	/**
	 * @param list<array<string,mixed>>|null $rules
	 * @return array{tags:list<string>,mark_read:bool,results:list<array<string,mixed>>,context:array<string,string>}
	 */
	public function runRules(FreshRSS_Entry $entry, ?array $rules = null, bool $logDiagnostics = true): array {
		$rules = $rules ?? $this->rules->enabled();
		$results = [];
		$tags = is_array($entry->tags(false)) ? $entry->tags(false) : [];
		$markRead = false;
		$profilesById = [];
		foreach ($this->profiles->all() as $profile) {
			$profilesById[$profile['id']] = $profile;
		}

		$contextsByMaxChars = [];
		$llmRulesByProfile = [];
		foreach ($rules as $rule) {
			if (!($rule['enabled'] ?? false)) {
				continue;
			}

			$profile = $profilesById[$rule['profile_id']] ?? null;
			if ($profile === null) {
				$results[] = $this->skippedResult($rule, 'missing_profile');
				continue;
			}
			if (!($profile['enabled'] ?? false)) {
				$results[] = $this->skippedResult($rule, 'profile_disabled');
				continue;
			}
			if (!$this->capabilities->supportsMode($profile, (string)$rule['mode'])) {
				$results[] = $this->skippedResult($rule, 'unsupported_mode');
				continue;
			}

			$maxChars = (int)$profile['content_max_chars'];
			if (!isset($contextsByMaxChars[$maxChars])) {
				$contextsByMaxChars[$maxChars] = $this->extractor->extractContext($entry, $maxChars);
			}
			$context = $contextsByMaxChars[$maxChars];
			$effectiveProfile = $this->effectiveProfile($profile);

			if ($rule['mode'] === 'llm') {
				$llmRulesByProfile[(string)$profile['id']][] = $rule;
				continue;
			}

			try {
				$result = $this->runEmbeddingRule($effectiveProfile, $rule, $context);
			} catch (Throwable $throwable) {
				$result = [
					'rule_id' => $rule['id'],
					'rule_name' => $rule['name'],
					'target_tags' => $rule['target_tags'],
					'mode' => $rule['mode'],
					'matched' => false,
					'status' => 'error',
					'reason' => $throwable->getMessage(),
				];
			}

			if (!empty($result['matched'])) {
				$markRead = $markRead || !empty($rule['mark_read_on_match']);
				foreach ($rule['target_tags'] as $targetTag) {
					$tags[] = (string)$targetTag;
				}
			}
			$results[] = $result;
		}

		foreach ($llmRulesByProfile as $profileId => $profileRules) {
			$profile = $profilesById[$profileId] ?? null;
			if (!is_array($profile)) {
				continue;
			}

			$batchResults = $this->runProfileBatch($profile, [[
				'task_id' => '0',
				'entry' => $entry,
				'rules' => $profileRules,
			]]);
			$entryResult = $batchResults['0'] ?? ['tags' => [], 'mark_read' => false, 'results' => []];
			$tags = array_merge($tags, is_array($entryResult['tags'] ?? null) ? $entryResult['tags'] : []);
			$markRead = $markRead || !empty($entryResult['mark_read']);
			$results = array_merge($results, is_array($entryResult['results'] ?? null) ? $entryResult['results'] : []);
		}

		$tags = array_values(array_unique(array_filter(array_map(
			static fn ($tag): string => ltrim(trim((string)$tag), '#'),
			$tags
		))));

		if ($logDiagnostics && count($results) > 0) {
			$this->diagnostics->append([
				'type' => 'entry_classification',
				'title' => $entry->title(),
				'results' => $results,
				'tags' => $tags,
				'mark_read' => $markRead,
			]);
		}

		return [
			'tags' => $tags,
			'mark_read' => $markRead,
			'results' => $results,
			'context' => $this->diagnosticContext(
				$contextsByMaxChars[AutoLabelSystemProfileRepository::DEFAULT_CONTENT_MAX_CHARS]
					?? $this->extractor->extractContext($entry, AutoLabelSystemProfileRepository::DEFAULT_CONTENT_MAX_CHARS)
			),
		];
	}

	/**
	 * @param array<string,mixed> $profile
	 * @return array<string,mixed>
	 */
	private function effectiveProfile(array $profile): array {
		if ($this->timeoutCapSeconds === null) {
			return $profile;
		}
		if (($profile['profile_mode'] ?? 'llm') === 'llm') {
			return $profile;
		}

		$currentTimeout = max(1, (int)($profile['timeout_seconds'] ?? AutoLabelSystemProfileRepository::DEFAULT_TIMEOUT_SECONDS));
		$profile['timeout_seconds'] = min($currentTimeout, $this->timeoutCapSeconds);
		return $profile;
	}

	/**
	 * @param array<string,string> $context
	 * @return array<string,string>
	 */
	private function diagnosticContext(array $context): array {
		$embeddingText = (string)($context['embedding_text'] ?? $context['text'] ?? '');
		$llmText = (string)($context['text'] ?? $embeddingText);
		$context['llm_text'] = $llmText;
		$context['embedding_text'] = $embeddingText;
		$context['text'] = $embeddingText;
		return $context;
	}

	/**
	 * @param array<string,mixed> $rule
	 */
	private function effectiveInstruction(array $profile, array $rule): string {
		$instruction = trim((string)($rule['embedding_instruction'] ?? ''));
		if ($instruction === '') {
			$instruction = trim((string)($profile['default_instruction'] ?? ''));
		}
		return $instruction;
	}

	/**
	 * @param list<array{task_id:string,entry:FreshRSS_Entry,rules:list<array<string,mixed>>}> $tasks
	 * @param array<string,array<string,string>> $contextsByTask
	 */
	private function buildLlmMatrixPrompt(array $tasks, array $contextsByTask): string {
		$items = [];
		foreach ($tasks as $task) {
			$taskId = (string)$task['task_id'];
			$context = $contextsByTask[$taskId] ?? [];
			foreach ($task['rules'] as $rule) {
				$items[] = "Task ID: {$taskId}\n"
					. "Rule ID: {$rule['id']}\n"
					. "Rule name: {$rule['name']}\n"
					. "Target tags: " . implode(', ', $rule['target_tags']) . "\n"
					. "Prompt:\n" . $this->buildLlmPrompt($rule, $context);
			}
		}

		return "Evaluate each task/rule item independently. Return JSON only in this exact format:\n"
			. "{\"results\":[{\"task_id\":\"task-id\",\"rule_id\":\"rule-id\",\"match\":true,\"confidence\":0.0,\"reason\":\"short explanation\"}]}\n"
			. "Include every requested task_id and rule_id pair exactly once. Do not add labels directly; only decide match values.\n\n"
			. implode("\n\n---\n\n", $items);
	}

	/**
	 * @param list<array{task_id:string,entry:FreshRSS_Entry,rules:list<array<string,mixed>>}> $tasks
	 */
	private function llmMatrixMaxOutputTokens(array $tasks): int {
		$decisionCount = 0;
		foreach ($tasks as $task) {
			$decisionCount += count($task['rules']);
		}
		return max(300, min(12000, 240 + ($decisionCount * 180)));
	}

	/**
	 * @return array<string,array<string,array{match:bool,confidence:float|null,reason:string}>>
	 */
	private function parseLlmMatrixDecisions(string $text, ?string $singleTaskId = null): array {
		$raw = trim($text);
		$decoded = json_decode($raw, true);
		if (!is_array($decoded) && preg_match('/\{.*\}/s', $raw, $matches) === 1) {
			$decoded = json_decode($matches[0], true);
		}
		if (!is_array($decoded)) {
			return [];
		}

		$decisions = [];
		foreach ($this->llmMatrixDecisionItems($decoded, $singleTaskId) as $item) {
			if (!is_array($item)) {
				continue;
			}
			$taskId = $this->matrixDecisionString($item, ['task_id', 'taskId', 'article_id', 'articleId', 'entry_id', 'entryId', 'item_id', 'itemId']);
			if ($taskId === '' && $singleTaskId !== null) {
				$taskId = $singleTaskId;
			}
			$ruleId = $this->matrixDecisionString($item, ['rule_id', 'ruleId', 'rule', 'id']);
			if ($taskId === '' || $ruleId === '') {
				continue;
			}
			$confidence = null;
			if (isset($item['confidence']) && is_numeric($item['confidence'])) {
				$confidence = max(0.0, min(1.0, (float)$item['confidence']));
			}
			if (!isset($decisions[$taskId])) {
				$decisions[$taskId] = [];
			}
			$decisions[$taskId][$ruleId] = [
				'match' => $this->matrixDecisionMatch($item),
				'confidence' => $confidence,
				'reason' => $this->matrixDecisionString($item, ['reason', 'explanation', 'rationale']),
			];
		}

		return $decisions;
	}

	/**
	 * @param mixed $value
	 * @return list<array<string,mixed>>
	 */
	private function llmMatrixDecisionItems($value, ?string $taskId = null, ?string $ruleId = null): array {
		if (!is_array($value)) {
			return [];
		}

		if ($this->isMatrixDecisionItem($value)) {
			$item = $value;
			if ($taskId !== null && $this->matrixDecisionString($item, ['task_id', 'taskId', 'article_id', 'articleId', 'entry_id', 'entryId', 'item_id', 'itemId']) === '') {
				$item['task_id'] = $taskId;
			}
			if ($ruleId !== null && $this->matrixDecisionString($item, ['rule_id', 'ruleId', 'rule', 'id']) === '') {
				$item['rule_id'] = $ruleId;
			}
			return [$item];
		}

		foreach (['results', 'decisions', 'classifications', 'items', 'matrix'] as $key) {
			if (is_array($value[$key] ?? null)) {
				return $this->llmMatrixDecisionItems($value[$key], $taskId, $ruleId);
			}
		}

		$items = [];
		if (array_is_list($value)) {
			foreach ($value as $index => $nested) {
				$nestedTaskId = $taskId;
				if ($nestedTaskId === null && is_array($nested) && !$this->isMatrixDecisionItem($nested)) {
					$nestedTaskId = (string)$index;
				}
				$items = array_merge($items, $this->llmMatrixDecisionItems($nested, $nestedTaskId, $ruleId));
			}
			return $items;
		}

		foreach ($value as $key => $nested) {
			if (!is_array($nested)) {
				continue;
			}
			$keyString = trim((string)$key);
			if ($taskId === null) {
				$items = array_merge($items, $this->llmMatrixDecisionItems($nested, $keyString !== '' ? $keyString : null, $ruleId));
				continue;
			}
			$items = array_merge($items, $this->llmMatrixDecisionItems($nested, $taskId, $keyString !== '' ? $keyString : $ruleId));
		}

		return $items;
	}

	/**
	 * @param array<string,mixed> $item
	 */
	private function isMatrixDecisionItem(array $item): bool {
		foreach (['match', 'matched', 'is_match', 'isMatch', 'decision'] as $key) {
			if (array_key_exists($key, $item)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $item
	 * @param list<string> $keys
	 */
	private function matrixDecisionString(array $item, array $keys): string {
		foreach ($keys as $key) {
			if (isset($item[$key]) && is_scalar($item[$key])) {
				return trim((string)$item[$key]);
			}
		}
		return '';
	}

	/**
	 * @param array<string,mixed> $item
	 */
	private function matrixDecisionMatch(array $item): bool {
		foreach (['match', 'matched', 'is_match', 'isMatch', 'decision'] as $key) {
			if (!array_key_exists($key, $item)) {
				continue;
			}
			$value = $item[$key];
			if (is_bool($value)) {
				return $value;
			}
			if (is_numeric($value)) {
				return ((float)$value) !== 0.0;
			}
			$normalized = strtolower(trim((string)$value));
			return in_array($normalized, ['true', 'yes', 'y', '1', 'match', 'matched', 'relevant'], true);
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $profile
	 * @param array<string,mixed> $rule
	 * @param array<string,string> $context
	 * @return array<string,mixed>
	 */
	private function runLlmRule(array $profile, array $rule, array $context): array {
		$provider = $this->providers->create((string)$profile['provider']);
		$prompt = $this->buildLlmPrompt($rule, $context);
		$decision = $provider->classify($profile, $prompt);

		return [
			'rule_id' => $rule['id'],
			'rule_name' => $rule['name'],
			'target_tags' => $rule['target_tags'],
			'mode' => 'llm',
			'matched' => $decision['match'],
			'status' => 'ok',
			'reason' => $decision['reason'],
			'confidence' => $decision['confidence'],
		];
	}

	/**
	 * @param array<string,mixed> $profile
	 * @param array<string,mixed> $rule
	 * @param array<string,string> $context
	 * @return array<string,mixed>
	 */
	private function runEmbeddingRule(array $profile, array $rule, array $context): array {
		$provider = $this->providers->create((string)$profile['provider']);
		$instruction = trim((string)$rule['embedding_instruction']);
		if ($instruction === '') {
			$instruction = trim((string)$profile['default_instruction']);
		}

		$embeddingText = (string)($context['embedding_text'] ?? $context['text']);
		$entryVectorKey = hash('sha256', implode('|', [
			$profile['provider'],
			$profile['model'],
			$profile['base_url'],
			(string)($profile['embedding_dimensions'] ?? 0),
			(string)($profile['embedding_num_ctx'] ?? 0),
			$instruction,
			$embeddingText,
		]));

		if (!isset($this->entryEmbeddingMemo[$entryVectorKey])) {
			$vectors = $provider->embedTexts($profile, [$embeddingText], $instruction);
			if (!isset($vectors[0])) {
				throw new RuntimeException('No embedding was returned for the entry.');
			}
			$this->entryEmbeddingMemo[$entryVectorKey] = $vectors[0];
		}
		$entryVector = $this->entryEmbeddingMemo[$entryVectorKey];

		$anchors = $rule['embedding_anchor_texts'];
		$uncached = [];
		$anchorVectors = [];
		foreach ($anchors as $anchor) {
			$cacheKey = $this->anchorCacheKey($profile, $instruction, (string)$anchor);
			$cachedVector = $this->cache->get($cacheKey);
			if ($cachedVector !== null) {
				$anchorVectors[(string)$anchor] = $cachedVector;
			} else {
				$uncached[] = (string)$anchor;
			}
		}

		if (count($uncached) > 0) {
			$uncachedVectors = $provider->embedTexts($profile, $uncached, $instruction);
			foreach ($uncached as $index => $anchor) {
				if (!isset($uncachedVectors[$index])) {
					throw new RuntimeException('Some anchor embeddings were not returned.');
				}
				$cacheKey = $this->anchorCacheKey($profile, $instruction, $anchor);
				$this->cache->set($cacheKey, $uncachedVectors[$index]);
				$anchorVectors[$anchor] = $uncachedVectors[$index];
			}
		}

		$threshold = (float)$rule['embedding_threshold'];
		$bestSimilarity = -1.0;
		$bestAnchor = '';
		foreach ($anchors as $anchor) {
			$anchor = (string)$anchor;
			if (!isset($anchorVectors[$anchor])) {
				continue;
			}
			$similarity = $this->cosineSimilarity($entryVector, $anchorVectors[$anchor]);
			if ($similarity > $bestSimilarity) {
				$bestSimilarity = $similarity;
				$bestAnchor = $anchor;
			}
		}

		return [
			'rule_id' => $rule['id'],
			'rule_name' => $rule['name'],
			'target_tags' => $rule['target_tags'],
			'mode' => 'embedding',
			'matched' => $bestSimilarity >= $threshold,
			'status' => 'ok',
			'reason' => $bestAnchor === '' ? '' : 'Best anchor: ' . $bestAnchor,
			'confidence' => $bestSimilarity < 0 ? null : round($bestSimilarity, 4),
			'threshold' => $threshold,
		];
	}

	/**
	 * @param array<string,mixed> $profile
	 */
	private function anchorCacheKey(array $profile, string $instruction, string $anchorText): string {
		return hash('sha256', implode('|', [
			(string)$profile['provider'],
			(string)$profile['model'],
			(string)$profile['base_url'],
			(string)($profile['embedding_dimensions'] ?? 0),
			(string)($profile['embedding_num_ctx'] ?? 0),
			$instruction,
			$anchorText,
		]));
	}

	/**
	 * @param array<string,mixed> $rule
	 * @param array<string,string> $context
	 */
	private function buildLlmPrompt(array $rule, array $context): string {
		$template = trim((string)$rule['llm_prompt']);
		if ($template === '') {
			$template = <<<TXT
Decide whether this article should receive the label "{{label}}".
Be conservative and only return match=true when the article clearly fits the label.
TXT;
		}

		$renderedTemplate = strtr($template, [
			'{{label}}' => implode(', ', $rule['target_tags']),
			'{{title}}' => $context['title'],
			'{{content}}' => $context['content'],
			'{{feed}}' => $context['feed'],
			'{{authors}}' => $context['authors'],
			'{{url}}' => $context['url'],
		]);

		return trim($renderedTemplate) . "\n\nArticle data:\n" . $context['text'];
	}

	/**
	 * @param list<float> $left
	 * @param list<float> $right
	 */
	private function cosineSimilarity(array $left, array $right): float {
		$count = min(count($left), count($right));
		if ($count === 0) {
			return -1.0;
		}

		$dot = 0.0;
		$leftNorm = 0.0;
		$rightNorm = 0.0;
		for ($index = 0; $index < $count; ++$index) {
			$dot += $left[$index] * $right[$index];
			$leftNorm += $left[$index] ** 2;
			$rightNorm += $right[$index] ** 2;
		}

		if ($leftNorm <= 0.0 || $rightNorm <= 0.0) {
			return -1.0;
		}

		return $dot / (sqrt($leftNorm) * sqrt($rightNorm));
	}

	/**
	 * @param array<string,mixed> $rule
	 * @return array<string,mixed>
	 */
	private function skippedResult(array $rule, string $status, string $reason = ''): array {
		return [
			'rule_id' => $rule['id'],
			'rule_name' => $rule['name'],
			'target_tags' => $rule['target_tags'],
			'mode' => $rule['mode'],
			'matched' => false,
			'status' => $status,
			'reason' => $reason,
		];
	}
}

final class AutoLabelEntryPersistence {
	/** @var array<string,int> */
	private static array $tagIdsByName = [];

	/**
	 * @return array{updated:bool,applied_tags:list<string>,failed_tags:list<string>,marked_read:bool,failed_read:bool}
	 */
	public static function updateTags($entryDao, FreshRSS_Entry $entry, array $tags, bool $markRead = false): array {
		$tags = self::normalizeTags($tags);
		$existingTags = self::normalizeTags(is_array($entry->tags(false)) ? $entry->tags(false) : []);
		$newTags = array_values(array_filter(
			$tags,
			static fn (string $tag): bool => !in_array($tag, $existingTags, true)
		));
		$resolvedTags = self::ensureTagsExist($newTags);
		$newlyAppliedTags = $resolvedTags['applied_tags'];
		$appliedTags = array_values(array_unique(array_merge($existingTags, $newlyAppliedTags)));
		$failedTags = $resolvedTags['failed_tags'];
		$shouldWriteTags = count($newlyAppliedTags) > 0;
		$shouldMarkRead = $markRead && !$entry->isRead();
		if (!$shouldWriteTags && !$shouldMarkRead) {
			return [
				'updated' => false,
				'applied_tags' => [],
				'failed_tags' => $failedTags,
				'marked_read' => false,
				'failed_read' => false,
			];
		}

		$tagsUpdated = false;
		if ($shouldWriteTags) {
			$tagsUpdated = AutoLabelQueueUpdateGuard::withoutQueueing(static function () use ($entryDao, $entry, $existingTags, $appliedTags, $newlyAppliedTags): bool {
				$entry->_tags($appliedTags);
				$payload = [
					'id' => $entry->id(),
					'guid' => $entry->guid(),
					'title' => $entry->title(),
					'author' => method_exists($entry, 'authors') ? (string)$entry->authors(true) : $entry->author(),
					'content' => $entry->content(false),
					'link' => $entry->link(true),
					'date' => (int)$entry->date(true),
					'lastSeen' => method_exists($entry, 'lastSeen') ? $entry->lastSeen() : 0,
					'lastModified' => method_exists($entry, 'lastModified') ? $entry->lastModified() : 0,
					'lastUserModified' => method_exists($entry, 'lastUserModified') ? $entry->lastUserModified() : 0,
					'hash' => $entry->hash(),
					'is_read' => $entry->isRead(),
					'is_favorite' => $entry->isFavorite(),
					'id_feed' => $entry->feedId(),
					'tags' => (string)$entry->tags(true),
					'attributes' => method_exists($entry, 'attributes') ? $entry->attributes() : [],
				];

				if (!(bool)$entryDao->updateEntry($payload)) {
					$entry->_tags($existingTags);
					return false;
				}

				self::ensureEntryTagLinks($entry, $newlyAppliedTags);
				return true;
			});
		}

		$markedRead = false;
		if ($shouldMarkRead) {
			$markedRead = AutoLabelQueueUpdateGuard::withoutQueueing(static function () use ($entryDao, $entry): bool {
				if (!method_exists($entryDao, 'markRead')) {
					return false;
				}

				$affected = $entryDao->markRead((string)$entry->id(), true);
				if ($affected === false || (int)$affected <= 0) {
					return false;
				}
				if (method_exists($entry, '_isRead')) {
					$entry->_isRead(true);
				}
				return true;
			});
		}

		return [
			'updated' => $tagsUpdated || $markedRead,
			'applied_tags' => $tagsUpdated ? $appliedTags : ($markedRead ? $existingTags : []),
			'failed_tags' => $failedTags,
			'marked_read' => $markedRead,
			'failed_read' => $shouldMarkRead && !$markedRead,
		];
	}

	/**
	 * @param list<string> $tags
	 * @return array{applied_tags:list<string>,failed_tags:list<string>}
	 */
	private static function ensureTagsExist(array $tags): array {
		if (count($tags) === 0) {
			return [
				'applied_tags' => [],
				'failed_tags' => [],
			];
		}

		$tagDao = FreshRSS_Factory::createTagDao();
		$appliedTags = [];
		$failedTags = [];
		foreach ($tags as $tagName) {
			if (isset(self::$tagIdsByName[$tagName]) && self::$tagIdsByName[$tagName] > 0) {
				$appliedTags[] = $tagName;
				continue;
			}

			$tag = null;
			foreach (self::tagLookupCandidates($tagName) as $candidateName) {
				$candidate = $tagDao->searchByName($candidateName);
				if ($candidate instanceof FreshRSS_Tag && $candidate->id() > 0) {
					$tag = $candidate;
					break;
				}
			}
			if ($tag instanceof FreshRSS_Tag && $tag->id() > 0) {
				$appliedTagName = ltrim(trim((string)$tag->name()), '#');
				if ($appliedTagName === '') {
					$appliedTagName = ltrim($tagName, '#');
				}
				self::$tagIdsByName[$tagName] = $tag->id();
				self::$tagIdsByName[$appliedTagName] = $tag->id();
				self::$tagIdsByName[(string)$tag->name()] = $tag->id();
				$appliedTags[] = $appliedTagName;
				continue;
			}

			$failedTags[] = $tagName;
			Minz_Log::warning('AutoLabel skipped a missing target tag: ' . $tagName);
		}

		return [
			'applied_tags' => array_values(array_unique($appliedTags)),
			'failed_tags' => array_values(array_unique($failedTags)),
		];
	}

	/**
	 * @return list<string>
	 */
	private static function tagLookupCandidates(string $tagName): array {
		$trimmed = trim($tagName);
		$withoutHash = ltrim($trimmed, '#');
		return array_values(array_unique(array_filter([
			$trimmed,
			$withoutHash,
			$withoutHash === '' ? '' : '#' . $withoutHash,
		], static fn (string $candidate): bool => $candidate !== '')));
	}

	/**
	 * @param list<string> $tags
	 */
	private static function ensureEntryTagLinks(FreshRSS_Entry $entry, array $tags): void {
		$entryId = (int)$entry->id();
		if ($entryId <= 0 || count($tags) === 0) {
			return;
		}

		$tagDao = FreshRSS_Factory::createTagDao();
		foreach ($tags as $tagName) {
			$tagId = self::$tagIdsByName[$tagName] ?? 0;
			if ($tagId <= 0) {
				continue;
			}
			$tagDao->tagEntry($tagId, (string)$entryId, true);
		}
	}

	/**
	 * @param list<string> $tags
	 * @return list<string>
	 */
	private static function normalizeTags(array $tags): array {
		$normalized = [];
		foreach ($tags as $tag) {
			$tag = ltrim(trim((string)$tag), '#');
			if ($tag !== '') {
				$normalized[$tag] = $tag;
			}
		}

		return array_values($normalized);
	}
}

final class AutoLabelQueueUpdateGuard {
	private static int $depth = 0;

	public static function isActive(): bool {
		return self::$depth > 0;
	}

	/**
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public static function withoutQueueing(callable $callback) {
		self::$depth++;
		try {
			return $callback();
		} finally {
			self::$depth = max(0, self::$depth - 1);
		}
	}
}

final class AutoLabelBackfillService {
	/** @var AutoLabelSystemProfileRepository */
	private $profiles;
	/** @var AutoLabelEngine */
	private $engine;
	/** @var AutoLabelDiagnosticsStore */
	private $diagnostics;
	/** @var AutoLabelNotificationService */
	private $notifications;

	public function __construct(
		AutoLabelSystemProfileRepository $profiles,
		AutoLabelEngine $engine,
		AutoLabelDiagnosticsStore $diagnostics,
		AutoLabelNotificationService $notifications
	) {
		$this->profiles = $profiles;
		$this->engine = $engine;
		$this->diagnostics = $diagnostics;
		$this->notifications = $notifications;
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @return array{processed:int,updated:int,matched_tags:int}
	 */
	public function run(array $rules, int $lookbackDays, int $limit): array {
		$state = [
			'lookback_days' => $lookbackDays,
			'limit' => $limit,
			'offset' => 0,
			'processed' => 0,
			'updated' => 0,
			'matched_tags' => 0,
			'aggregate_entries' => 0,
			'aggregate_entry_keys' => [],
			'aggregate_entry_attempts' => 0,
			'aggregate_requests' => 0,
			'concurrent_entries' => 0,
			'fallback_entries' => 0,
		];
		$summary = ['processed' => 0, 'updated' => 0, 'matched_tags' => 0, 'aggregate_entries' => 0, 'concurrent_entries' => 0, 'fallback_entries' => 0];
		do {
			$result = $this->processJobSlice($rules, $state);
			$state = $result['state'];
			$summary['processed'] = (int)$state['processed'];
			$summary['updated'] = (int)$state['updated'];
			$summary['matched_tags'] = (int)$state['matched_tags'];
			$summary['aggregate_entries'] = (int)($state['aggregate_entries'] ?? 0);
			$summary['concurrent_entries'] = (int)($state['concurrent_entries'] ?? 0);
			$summary['fallback_entries'] = (int)($state['fallback_entries'] ?? 0);
			if (!empty($result['deferred'])) {
				break;
			}
		} while (empty($result['finished']));

		return $summary;
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @param array<string,mixed> $state
	 * @return array{state:array<string,mixed>,finished:bool,deferred:bool}
	 */
	public function processJobSlice(array $rules, array $state, ?int $maxEntriesOverride = null): array {
		if (count($rules) === 0) {
			return ['state' => $state, 'finished' => true, 'deferred' => false];
		}

		$limit = max(1, min(1000, (int)($state['limit'] ?? 0)));
		$lookbackDays = max(1, min(3650, (int)($state['lookback_days'] ?? 0)));
		$cutoff = time() - ($lookbackDays * 86400);
		$entryDao = FreshRSS_Factory::createEntryDao();
		$processed = max(0, (int)($state['processed'] ?? 0));
		$updated = max(0, (int)($state['updated'] ?? 0));
		$matchedTags = max(0, (int)($state['matched_tags'] ?? 0));
		$totalAggregateEntries = max(0, (int)($state['aggregate_entries'] ?? 0));
		$aggregateEntryKeys = array_values(array_filter(is_array($state['aggregate_entry_keys'] ?? null) ? $state['aggregate_entry_keys'] : [], 'is_string'));
		$aggregateEntryKeyMap = array_fill_keys($aggregateEntryKeys, true);
		if (count($aggregateEntryKeyMap) > 0) {
			$totalAggregateEntries = count($aggregateEntryKeyMap);
		}
		$totalAggregateEntryAttempts = max(0, (int)($state['aggregate_entry_attempts'] ?? 0));
		$totalAggregateRequests = max(0, (int)($state['aggregate_requests'] ?? 0));
		$totalConcurrentEntries = max(0, (int)($state['concurrent_entries'] ?? 0));
		$totalFallbackEntries = max(0, (int)($state['fallback_entries'] ?? 0));
		$offset = max(0, (int)($state['offset'] ?? 0));
		$pendingQueue = array_values(array_filter(is_array($state['pending_queue'] ?? null) ? $state['pending_queue'] : [], 'is_array'));
		$finishReason = trim((string)($state['finish_reason'] ?? ''));
		$latestCandidateDate = trim((string)($state['latest_candidate_date'] ?? ''));
		$latestCandidateTitle = trim((string)($state['latest_candidate_title'] ?? ''));
		$fetchBatchSize = $this->resolveFetchBatchSize($rules);
		$profiles = $this->profilesForRules($rules);
		if (count($profiles) === 0) {
			return [
				'state' => [
					'lookback_days' => $lookbackDays,
					'limit' => $limit,
					'offset' => $offset,
					'processed' => $processed,
					'updated' => $updated,
					'matched_tags' => $matchedTags,
					'aggregate_entries' => $totalAggregateEntries,
					'aggregate_entry_keys' => array_keys($aggregateEntryKeyMap),
					'aggregate_entry_attempts' => $totalAggregateEntryAttempts,
					'aggregate_requests' => $totalAggregateRequests,
					'concurrent_entries' => $totalConcurrentEntries,
					'fallback_entries' => $totalFallbackEntries,
					'finish_reason' => 'no_enabled_profiles',
					'pending_queue' => [],
				],
				'finished' => true,
				'deferred' => false,
			];
		}

		$remaining = $limit - $processed;
		if ($remaining <= 0) {
			return [
				'state' => [
					'lookback_days' => $lookbackDays,
					'limit' => $limit,
					'offset' => $offset,
					'processed' => $processed,
					'updated' => $updated,
					'matched_tags' => $matchedTags,
					'aggregate_entries' => $totalAggregateEntries,
					'aggregate_entry_keys' => array_keys($aggregateEntryKeyMap),
					'aggregate_entry_attempts' => $totalAggregateEntryAttempts,
					'aggregate_requests' => $totalAggregateRequests,
					'concurrent_entries' => $totalConcurrentEntries,
					'fallback_entries' => $totalFallbackEntries,
					'finish_reason' => 'limit_reached',
					'latest_candidate_date' => $latestCandidateDate,
					'latest_candidate_title' => $latestCandidateTitle,
					'pending_queue' => $pendingQueue,
				],
				'finished' => true,
				'deferred' => false,
			];
		}

		$currentBatchSize = min($fetchBatchSize, $remaining);
		if ($maxEntriesOverride !== null) {
			$currentBatchSize = min($currentBatchSize, max(1, $maxEntriesOverride));
		}
		$selected = [];
		$selectedCountsByProfile = [];
		$deferredQueue = [];
		$exhausted = false;
		$batchSequence = 0;

		while (count($selected) < $currentBatchSize) {
			$queuedCandidate = array_shift($pendingQueue);
			$entry = null;
			$entryDescriptor = [];
			$candidateRules = [];
			$attempts = 0;

			if (is_array($queuedCandidate)) {
				$entryDescriptor = is_array($queuedCandidate['entry'] ?? null) ? $queuedCandidate['entry'] : [];
				$attempts = max(0, (int)($queuedCandidate['attempts'] ?? 0));
				$entry = $this->resolveBackfillDescriptor($entryDao, $entryDescriptor);
				$candidateRules = $this->rulesForBackfillQueueItem($rules, is_array($queuedCandidate['rule_ids'] ?? null) ? $queuedCandidate['rule_ids'] : []);
				if (!$entry instanceof FreshRSS_Entry) {
					++$processed;
					$this->diagnostics->append([
						'type' => 'backfill_entry',
						'entry_title' => (string)($entryDescriptor['title'] ?? ''),
						'result' => ['results' => []],
						'updated' => false,
						'failed_tags' => [],
						'error' => 'Entry could not be resolved for backfill retry.',
					]);
					continue;
				}
			} else {
				$entries = $entryDao->listWhere('a', 0, FreshRSS_Entry::STATE_ALL, null, '0', '0', 'date', 'DESC', '0', [], 1, $offset, 'id', 'DESC');
				$offset++;
				$entry = null;
				if (is_iterable($entries)) {
					foreach ($entries as $candidateEntry) {
						$entry = $candidateEntry;
						break;
					}
				}
				if (!$entry instanceof FreshRSS_Entry) {
					$exhausted = true;
					$finishReason = 'no_more_entries';
					break;
				}
				if ((int)$entry->date(true) < $cutoff) {
					$exhausted = true;
					$finishReason = 'latest_entry_older_than_lookback';
					$latestCandidateDate = date(DATE_ATOM, (int)$entry->date(true));
					$latestCandidateTitle = trim((string)$entry->title());
					break;
				}

				$entryDescriptor = $this->backfillEntryDescriptor($entry);
				$candidateRules = $rules;
			}

			if (count($candidateRules) === 0) {
				++$processed;
				continue;
			}

			$rulesByProfile = $this->groupRulesByProfile($candidateRules);
			if (count($rulesByProfile) === 0) {
				if (!$this->engine->supportsConcurrentWindow() && $this->hasEmbeddingRule($candidateRules)) {
					$deferredQueue[] = [
						'entry' => $entryDescriptor,
						'rule_ids' => array_values(array_map(static fn (array $rule): string => (string)$rule['id'], $candidateRules)),
						'attempts' => $attempts,
					];
					if ($queuedCandidate === null) {
						break;
					}
				} else {
					++$processed;
				}
				continue;
			}
			$deferredRuleIds = $this->ruleIdsNotInGroups($candidateRules, $rulesByProfile);

			$fitsWindow = true;
			foreach ($rulesByProfile as $profileId => $profileRules) {
				$profile = $this->profiles->find((string)$profileId);
				if (!is_array($profile) || empty($profile['enabled'])) {
					continue;
				}
				$limitForProfile = AutoLabelSystemProfileRepository::normalizeBatchSize((int)($profile['batch_size'] ?? AutoLabelSystemProfileRepository::DEFAULT_BATCH_SIZE));
				if (($selectedCountsByProfile[$profileId] ?? 0) >= $limitForProfile) {
					$fitsWindow = false;
					break;
				}
			}

			if (!$fitsWindow) {
				$deferredQueue[] = [
					'entry' => $entryDescriptor,
					'rule_ids' => array_values(array_map(static fn (array $rule): string => (string)$rule['id'], $candidateRules)),
					'attempts' => $attempts,
				];
				if ($queuedCandidate === null) {
					continue;
				}
				continue;
			}

			foreach ($rulesByProfile as $profileId => $_profileRules) {
				$selectedCountsByProfile[$profileId] = ($selectedCountsByProfile[$profileId] ?? 0) + 1;
			}

			$selected[] = [
				'entry' => $entry,
				'entry_descriptor' => $entryDescriptor,
				'rules' => $candidateRules,
				'rules_by_profile' => $rulesByProfile,
				'deferred_rule_ids' => $deferredRuleIds,
				'attempts' => $attempts,
			];
		}

		if (count($selected) === 0) {
			return [
				'state' => [
					'lookback_days' => $lookbackDays,
					'limit' => $limit,
					'offset' => $offset,
					'processed' => $processed,
					'updated' => $updated,
					'matched_tags' => $matchedTags,
					'aggregate_entries' => $totalAggregateEntries,
					'aggregate_entry_keys' => array_keys($aggregateEntryKeyMap),
					'aggregate_entry_attempts' => $totalAggregateEntryAttempts,
					'aggregate_requests' => $totalAggregateRequests,
					'concurrent_entries' => $totalConcurrentEntries,
					'fallback_entries' => $totalFallbackEntries,
					'finish_reason' => $finishReason,
					'latest_candidate_date' => $latestCandidateDate,
					'latest_candidate_title' => $latestCandidateTitle,
					'pending_queue' => array_values(array_merge($deferredQueue, $pendingQueue)),
				],
				'finished' => $exhausted && count($deferredQueue) === 0 && count($pendingQueue) === 0,
				'deferred' => false,
			];
		}

		$resultsByEntry = [];
		foreach ($selected as $index => $candidate) {
			$resultsByEntry[$index] = [
				'tags' => is_array($candidate['entry']->tags(false)) ? $candidate['entry']->tags(false) : [],
				'mark_read' => false,
				'results' => [],
				'failed_rule_ids' => [],
				'failed_tags' => [],
			];
		}

		foreach ($selectedCountsByProfile as $profileId => $countForProfile) {
			$profile = $this->profiles->find((string)$profileId);
			if (!is_array($profile) || empty($profile['enabled'])) {
				continue;
			}
			$tasks = [];
			foreach ($selected as $index => $candidate) {
				if (!isset($candidate['rules_by_profile'][$profileId])) {
					continue;
				}
				$tasks[] = [
					'task_id' => (string)$index,
					'entry' => $candidate['entry'],
					'rules' => $candidate['rules_by_profile'][$profileId],
				];
			}
			if (count($tasks) === 0) {
				continue;
			}

			$startedAt = microtime(true);
			$batchResults = $this->engine->runProfileBatch($profile, $tasks);
			++$batchSequence;
			$failedEntries = 0;
			$aggregateEntries = 0;
			$concurrentEntries = 0;
			$fallbackEntries = 0;
			foreach ($tasks as $task) {
				$taskId = (string)$task['task_id'];
				$result = $batchResults[$taskId] ?? [
					'tags' => [],
					'mark_read' => false,
					'results' => [],
					'failed_rule_ids' => array_values(array_map(static fn (array $rule): string => (string)$rule['id'], $task['rules'])),
					'transport' => 'concurrent',
				];
				$resultsByEntry[(int)$taskId]['tags'] = array_values(array_unique(array_merge($resultsByEntry[(int)$taskId]['tags'], $result['tags'] ?? [])));
				$resultsByEntry[(int)$taskId]['mark_read'] = !empty($resultsByEntry[(int)$taskId]['mark_read']) || !empty($result['mark_read']);
				$resultsByEntry[(int)$taskId]['results'] = array_merge($resultsByEntry[(int)$taskId]['results'], $result['results'] ?? []);
				$resultsByEntry[(int)$taskId]['failed_rule_ids'] = array_values(array_unique(array_merge($resultsByEntry[(int)$taskId]['failed_rule_ids'], $result['failed_rule_ids'] ?? [])));
				$resultsByEntry[(int)$taskId]['transport'] = (string)($result['transport'] ?? 'concurrent');
				if (($result['transport'] ?? 'concurrent') === 'aggregate') {
					$aggregateEntries++;
					$totalAggregateEntryAttempts++;
					$entryKey = $this->diagnosticEntryKey(is_array($selected[(int)$taskId]['entry_descriptor'] ?? null) ? $selected[(int)$taskId]['entry_descriptor'] : []);
					if ($entryKey !== '') {
						$aggregateEntryKeyMap[$entryKey] = true;
					}
				} elseif (($result['transport'] ?? 'concurrent') === 'fallback_retry') {
					$fallbackEntries++;
				} else {
					$concurrentEntries++;
				}
				if (count($result['failed_rule_ids'] ?? []) > 0) {
					$failedEntries++;
				}
			}

			$this->diagnostics->append([
				'type' => 'backfill_batch',
				'profile_id' => $profileId,
				'profile_name' => (string)($profile['name'] ?? $profileId),
				'batch_index' => $batchSequence,
				'window_size' => $countForProfile,
				'processed_entries' => count($tasks),
				'aggregate_entries' => $aggregateEntries,
				'aggregate_entry_attempts' => $aggregateEntries,
				'aggregate_requests' => $aggregateEntries > 0 ? 1 : 0,
				'concurrent_entries' => $concurrentEntries,
				'fallback_entries' => $fallbackEntries,
				'failed_entries' => $failedEntries,
				'execution_mode' => $this->diagnosticExecutionMode($aggregateEntries, $concurrentEntries, $fallbackEntries),
				'remaining_entries' => max(0, $limit - $processed),
				'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
			]);
			if ($aggregateEntries > 0) {
				$totalAggregateRequests++;
				$totalAggregateEntries = count($aggregateEntryKeyMap);
			}
			$totalConcurrentEntries += $concurrentEntries;
			$totalFallbackEntries += $fallbackEntries;
		}

		foreach ($selected as $index => $candidate) {
			$entry = $candidate['entry'];
			$entryResult = $resultsByEntry[$index];
			$beforeTags = is_array($entry->tags(false)) ? $entry->tags(false) : [];
			$persist = ['updated' => false, 'applied_tags' => [], 'failed_tags' => [], 'marked_read' => false, 'failed_read' => false];
			$shouldMarkRead = !empty($entryResult['mark_read']) && !$entry->isRead();
			if (count($entryResult['tags']) > count($beforeTags) || $shouldMarkRead) {
				$persist = AutoLabelEntryPersistence::updateTags($entryDao, $entry, $entryResult['tags'], !empty($entryResult['mark_read']));
				if (!empty($persist['updated'])) {
					++$updated;
					$matchedTags += max(0, count($persist['applied_tags']) - count($beforeTags));
					$this->notifications->recordMatches($entry, $beforeTags, $persist, is_array($entryResult['results'] ?? null) ? $entryResult['results'] : [], 'backfill');
				}
			}

			$this->diagnostics->append([
				'type' => 'backfill_entry',
				'entry_title' => $entry->title(),
				'result' => [
					'tags' => $entryResult['tags'],
					'mark_read' => !empty($entryResult['mark_read']),
					'results' => $entryResult['results'],
					'transport' => $entryResult['transport'] ?? 'concurrent',
				],
				'updated' => !empty($persist['updated']),
				'failed_tags' => is_array($persist['failed_tags'] ?? null) ? $persist['failed_tags'] : [],
				'marked_read' => !empty($persist['marked_read']),
				'failed_read' => !empty($persist['failed_read']),
			]);

			$deferredRuleIds = is_array($candidate['deferred_rule_ids'] ?? null) ? $candidate['deferred_rule_ids'] : [];
			$retryRuleIds = array_values(array_unique(array_merge($entryResult['failed_rule_ids'], $deferredRuleIds)));
			if (count($retryRuleIds) > 0) {
				$attempts = (int)$candidate['attempts'] + (count($entryResult['failed_rule_ids']) > 0 ? 1 : 0);
				if ($attempts >= 3 && count($deferredRuleIds) > 0) {
					$retryRuleIds = $deferredRuleIds;
				}
				if ($attempts < 3 || count($deferredRuleIds) > 0) {
					$deferredQueue[] = [
						'entry' => $candidate['entry_descriptor'],
						'rule_ids' => $retryRuleIds,
						'attempts' => $attempts,
					];
					continue;
				}
			}

			++$processed;
		}

		return [
			'state' => [
				'lookback_days' => $lookbackDays,
				'limit' => $limit,
				'offset' => $offset,
				'processed' => $processed,
				'updated' => $updated,
				'matched_tags' => $matchedTags,
				'aggregate_entries' => $totalAggregateEntries,
				'aggregate_entry_keys' => array_keys($aggregateEntryKeyMap),
				'aggregate_entry_attempts' => $totalAggregateEntryAttempts,
				'aggregate_requests' => $totalAggregateRequests,
				'concurrent_entries' => $totalConcurrentEntries,
				'fallback_entries' => $totalFallbackEntries,
				'finish_reason' => $processed >= $limit ? 'limit_reached' : ($exhausted ? $finishReason : ''),
				'latest_candidate_date' => $latestCandidateDate,
				'latest_candidate_title' => $latestCandidateTitle,
				'pending_queue' => array_values(array_merge($deferredQueue, $pendingQueue)),
			],
			'finished' => $processed >= $limit || ($exhausted && count($deferredQueue) === 0 && count($pendingQueue) === 0),
			'deferred' => false,
		];
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @return list<array<string,mixed>>
	 */
	private function profilesForRules(array $rules): array {
		$profilesById = [];
		foreach ($rules as $rule) {
			$profileId = (string)($rule['profile_id'] ?? '');
			if ($profileId === '' || isset($profilesById[$profileId])) {
				continue;
			}

			$profile = $this->profiles->find($profileId);
			if (is_array($profile) && !empty($profile['enabled'])) {
				$profilesById[$profileId] = $profile;
			}
		}

		return array_values($profilesById);
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 */
	private function resolveFetchBatchSize(array $rules): int {
		$profiles = $this->profilesForRules($rules);
		$batchSize = 1;
		foreach ($profiles as $profile) {
			$batchSize = max(
				$batchSize,
				AutoLabelSystemProfileRepository::normalizeBatchSize((int)($profile['batch_size'] ?? AutoLabelSystemProfileRepository::DEFAULT_BATCH_SIZE))
			);
		}

		return $batchSize;
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @param list<string> $ruleIds
	 * @return list<array<string,mixed>>
	 */
	private function rulesForBackfillQueueItem(array $rules, array $ruleIds): array {
		if (count($ruleIds) === 0) {
			return $rules;
		}

		$wanted = array_fill_keys(array_map(static fn ($ruleId): string => trim((string)$ruleId), $ruleIds), true);
		$filtered = [];
		foreach ($rules as $rule) {
			if (!isset($wanted[(string)$rule['id']])) {
				continue;
			}
			$filtered[] = $rule;
		}

		return $filtered;
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @return array<string,list<array<string,mixed>>>
	 */
	private function groupRulesByProfile(array $rules): array {
		$grouped = [];
		foreach ($rules as $rule) {
			if (!($rule['enabled'] ?? false)) {
				continue;
			}
			$profileId = trim((string)($rule['profile_id'] ?? ''));
			if ($profileId === '') {
				continue;
			}
			$profile = $this->profiles->find($profileId);
			if (!is_array($profile) || empty($profile['enabled'])) {
				continue;
			}
			if ((string)($rule['mode'] ?? '') === 'embedding' && !$this->engine->supportsConcurrentWindow()) {
				continue;
			}
			$grouped[$profileId][] = $rule;
		}
		return $grouped;
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 */
	private function hasEmbeddingRule(array $rules): bool {
		foreach ($rules as $rule) {
			if ((string)($rule['mode'] ?? '') === 'embedding') {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @param array<string,list<array<string,mixed>>> $rulesByProfile
	 * @return list<string>
	 */
	private function ruleIdsNotInGroups(array $rules, array $rulesByProfile): array {
		$runnable = [];
		foreach ($rulesByProfile as $groupRules) {
			foreach ($groupRules as $rule) {
				$runnable[(string)$rule['id']] = true;
			}
		}

		$deferred = [];
		foreach ($rules as $rule) {
			$ruleId = (string)($rule['id'] ?? '');
			if ($ruleId !== '' && !isset($runnable[$ruleId])) {
				$deferred[$ruleId] = $ruleId;
			}
		}
		return array_values($deferred);
	}

	private function diagnosticExecutionMode(int $aggregateEntries, int $concurrentEntries, int $fallbackEntries): string {
		$modes = 0;
		$modes += $aggregateEntries > 0 ? 1 : 0;
		$modes += $concurrentEntries > 0 ? 1 : 0;
		$modes += $fallbackEntries > 0 ? 1 : 0;
		if ($modes > 1) {
			return 'mixed';
		}
		if ($aggregateEntries > 0) {
			return 'aggregate';
		}
		if ($fallbackEntries > 0) {
			return 'fallback_retry';
		}
		return 'concurrent';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function backfillEntryDescriptor(FreshRSS_Entry $entry): array {
		return [
			'entry_id' => method_exists($entry, 'id') ? (int)$entry->id() : 0,
			'feed_id' => method_exists($entry, 'feedId') ? (int)$entry->feedId() : 0,
			'guid' => method_exists($entry, 'guid') ? trim((string)$entry->guid()) : '',
			'link' => method_exists($entry, 'link') ? trim((string)$entry->link(true)) : '',
			'title' => method_exists($entry, 'title') ? trim((string)$entry->title()) : '',
			'date' => method_exists($entry, 'date') ? (int)$entry->date(true) : time(),
		];
	}

	/**
	 * @param array<string,mixed> $descriptor
	 */
	private function diagnosticEntryKey(array $descriptor): string {
		$entryId = (int)($descriptor['entry_id'] ?? 0);
		if ($entryId > 0) {
			return 'id:' . (string)$entryId;
		}

		$feedId = (int)($descriptor['feed_id'] ?? 0);
		$guid = trim((string)($descriptor['guid'] ?? ''));
		if ($feedId > 0 && $guid !== '') {
			return 'guid:' . (string)$feedId . ':' . $guid;
		}

		$link = trim((string)($descriptor['link'] ?? ''));
		if ($link !== '') {
			return 'link:' . $link;
		}

		$title = trim((string)($descriptor['title'] ?? ''));
		$date = max(0, (int)($descriptor['date'] ?? 0));
		if ($title !== '' || $date > 0) {
			return 'title:' . hash('sha256', $title . '|' . (string)$date);
		}

		return '';
	}

	private function resolveBackfillDescriptor($entryDao, array $descriptor): ?FreshRSS_Entry {
		$entryId = (int)($descriptor['entry_id'] ?? 0);
		if ($entryId > 0 && method_exists($entryDao, 'searchById')) {
			$entry = $entryDao->searchById((string)$entryId);
			if ($entry instanceof FreshRSS_Entry) {
				return $entry;
			}
		}

		$feedId = (int)($descriptor['feed_id'] ?? 0);
		$guid = trim((string)($descriptor['guid'] ?? ''));
		if ($feedId > 0 && $guid !== '' && method_exists($entryDao, 'searchByGuid')) {
			$entry = $entryDao->searchByGuid($feedId, $guid);
			if ($entry instanceof FreshRSS_Entry) {
				return $entry;
			}
		}

		return null;
	}
}

final class AutoLabelQueueProcessor {
	private const MAX_ENTRY_RESOLVE_ATTEMPTS = 5;
	private const ENTRY_RETRY_DELAY_SECONDS = 30;
	private const ENTRY_STALE_AFTER_SECONDS = 172800;
	private const ENTRY_SCAN_LIMIT = 500;
	private const ENTRY_SCAN_BATCH_SIZE = 100;
	private const DEFAULT_MAX_RUNTIME_SECONDS = 8.0;
	private const DEFAULT_MAX_PROCESSED_ITEMS = 20;
	private const DEFAULT_MAX_BACKFILL_ENTRIES = null;

	/** @var AutoLabelQueueStore */
	private $queue;
	/** @var AutoLabelSystemProfileRepository */
	private $profiles;
	/** @var AutoLabelUserRuleRepository */
	private $rules;
	/** @var AutoLabelEngine */
	private $engine;
	/** @var AutoLabelDiagnosticsStore */
	private $diagnostics;
	/** @var AutoLabelBackfillService */
	private $backfill;
	/** @var AutoLabelNotificationService */
	private $notifications;

	public function __construct(
		AutoLabelQueueStore $queue,
		AutoLabelSystemProfileRepository $profiles,
		AutoLabelUserRuleRepository $rules,
		AutoLabelEngine $engine,
		AutoLabelDiagnosticsStore $diagnostics,
		AutoLabelBackfillService $backfill,
		AutoLabelNotificationService $notifications
	) {
		$this->queue = $queue;
		$this->profiles = $profiles;
		$this->rules = $rules;
		$this->engine = $engine;
		$this->diagnostics = $diagnostics;
		$this->backfill = $backfill;
		$this->notifications = $notifications;
	}

	/**
	 * @return array{processed_items:int,processed_entries:int,updated_entries:int,matched_tags:int,remaining_items:int}
	 */
	/**
	 * @param array{max_runtime_seconds?:float,max_processed_items?:int,max_backfill_entries?:int|null,profile_timeout_cap_seconds?:int|null,source?:string} $options
	 * @return array{processed_items:int,processed_entries:int,updated_entries:int,matched_tags:int,remaining_items:int}
	 */
	public function process(array $options = []): array {
		$initialVersion = $this->queue->version();
		$items = $this->queue->allItems();
		usort($items, static function (array $left, array $right): int {
			$leftPriority = ($left['type'] ?? '') === 'entry' ? 0 : 1;
			$rightPriority = ($right['type'] ?? '') === 'entry' ? 0 : 1;
			if ($leftPriority !== $rightPriority) {
				return $leftPriority <=> $rightPriority;
			}

			return strcmp((string)($left['enqueued_at'] ?? ''), (string)($right['enqueued_at'] ?? ''));
		});
		$remainingItems = [];
		$stats = [
			'processed_items' => 0,
			'processed_entries' => 0,
			'updated_entries' => 0,
			'matched_tags' => 0,
			'remaining_items' => 0,
		];
		$maxRuntimeSeconds = isset($options['max_runtime_seconds']) ? max(0.1, (float)$options['max_runtime_seconds']) : self::DEFAULT_MAX_RUNTIME_SECONDS;
		$maxProcessedItems = isset($options['max_processed_items']) ? max(1, (int)$options['max_processed_items']) : self::DEFAULT_MAX_PROCESSED_ITEMS;
		$maxBackfillEntries = array_key_exists('max_backfill_entries', $options) && $options['max_backfill_entries'] !== null
			? max(1, (int)$options['max_backfill_entries'])
			: self::DEFAULT_MAX_BACKFILL_ENTRIES;
		$profileTimeoutCapSeconds = array_key_exists('profile_timeout_cap_seconds', $options) && $options['profile_timeout_cap_seconds'] !== null
			? max(1, (int)$options['profile_timeout_cap_seconds'])
			: null;
		$source = trim((string)($options['source'] ?? 'unspecified'));
		if ($source === '') {
			$source = 'unspecified';
		}
		$startedAt = microtime(true);
		$this->engine->setTimeoutCap($profileTimeoutCapSeconds);

		try {
			if (!$this->engine->supportsConcurrentWindow()) {
				$this->diagnostics->append([
					'type' => 'queue_concurrency_unavailable',
					'source' => $source,
					'message' => 'Embedding batch execution requires the PHP curl extension with curl_multi support. LLM aggregate batches can still run.',
				]);
			}

			$remainingItems = $items;
			do {
				$madeProgress = false;
				if ($stats['processed_items'] < $maxProcessedItems && (microtime(true) - $startedAt) < $maxRuntimeSeconds) {
					$entryPass = $this->processConcurrentEntryPass(
						$remainingItems,
						$maxProcessedItems - $stats['processed_items']
					);
					$remainingItems = $entryPass['items'];
					$madeProgress = $madeProgress || $entryPass['made_progress'];
					$stats['processed_items'] += $entryPass['stats']['processed_items'];
					$stats['processed_entries'] += $entryPass['stats']['processed_entries'];
					$stats['updated_entries'] += $entryPass['stats']['updated_entries'];
					$stats['matched_tags'] += $entryPass['stats']['matched_tags'];
				}

				if ($stats['processed_items'] < $maxProcessedItems && (microtime(true) - $startedAt) < $maxRuntimeSeconds) {
					$backfillPass = $this->processConcurrentBackfillPass(
						$remainingItems,
						$maxProcessedItems - $stats['processed_items'],
						$maxBackfillEntries
					);
					$remainingItems = $backfillPass['items'];
					$madeProgress = $madeProgress || $backfillPass['made_progress'];
					$stats['processed_items'] += $backfillPass['stats']['processed_items'];
					$stats['processed_entries'] += $backfillPass['stats']['processed_entries'];
					$stats['updated_entries'] += $backfillPass['stats']['updated_entries'];
					$stats['matched_tags'] += $backfillPass['stats']['matched_tags'];
				}

				if (!$madeProgress) {
					break;
				}
			} while ($stats['processed_items'] < $maxProcessedItems && (microtime(true) - $startedAt) < $maxRuntimeSeconds);
		} finally {
			$this->engine->setTimeoutCap(null);
		}

		$stats['remaining_items'] = count($remainingItems);
		$stored = $this->queue->replaceItems($remainingItems, [
			'at' => date(DATE_ATOM),
			'stats' => $stats,
		], $initialVersion);
		if (!$stored) {
			$stats['remaining_items'] = count($this->queue->allItems());
			$this->diagnostics->append([
				'type' => 'queue_version_conflict',
				'source' => $source,
				'stats' => $stats,
			]);
		}

		if ($stats['processed_items'] > 0 || $stats['updated_entries'] > 0) {
			$this->diagnostics->append([
				'type' => 'queue_run',
				'source' => $source,
				'stats' => $stats,
			]);
		}
		if ($stored && $stats['remaining_items'] === 0) {
			$this->notifications->flushEventDigest();
			$this->notifications->flushEmailDigest();
		}

		return $stats;
	}

	/**
	 * @param list<array<string,mixed>> $items
	 * @return array{items:list<array<string,mixed>>,stats:array{processed_items:int,processed_entries:int,updated_entries:int,matched_tags:int},made_progress:bool}
	 */
	private function processConcurrentEntryPass(array $items, int $itemBudget): array {
		$stats = [
			'processed_items' => 0,
			'processed_entries' => 0,
			'updated_entries' => 0,
			'matched_tags' => 0,
		];
		if ($itemBudget <= 0) {
			return ['items' => $items, 'stats' => $stats, 'made_progress' => false];
		}

		$selectedIndexes = [];
		$selectedStates = [];
		$selectedCountsByProfile = [];
		$itemsToRemove = [];
		$retryItemsByIndex = [];
		$now = time();

		foreach ($items as $index => $item) {
			if (($item['type'] ?? '') !== 'entry') {
				continue;
			}
			if (count($selectedStates) >= $itemBudget) {
				break;
			}
			if ((int)($item['next_attempt_at'] ?? 0) > $now) {
				continue;
			}

			$rules = $this->rulesForItem($item);
			if (count($rules) === 0) {
				$itemsToRemove[$index] = true;
				$stats['processed_items']++;
				continue;
			}

			$entryDescriptor = is_array($item['entry'] ?? null) ? $item['entry'] : [];
			$entry = $this->resolveQueuedEntry($entryDescriptor);
			if (!$entry instanceof FreshRSS_Entry) {
				$item['attempts'] = (int)($item['attempts'] ?? 0) + 1;
				$item['next_attempt_at'] = $now + self::ENTRY_RETRY_DELAY_SECONDS;
				$isStale = $this->entryDescriptorDate($entryDescriptor) < ($now - self::ENTRY_STALE_AFTER_SECONDS);
				if ($item['attempts'] >= self::MAX_ENTRY_RESOLVE_ATTEMPTS || $isStale) {
					$this->diagnostics->append([
						'type' => 'queue_drop',
						'reason' => 'entry_not_found',
						'item' => $item,
					]);
					$itemsToRemove[$index] = true;
					$stats['processed_items']++;
				} else {
					$retryItemsByIndex[$index] = $item;
				}
				continue;
			}

			$rulesByProfile = $this->groupRulesByProfile($rules);
			if (count($rulesByProfile) === 0) {
				if (!$this->engine->supportsConcurrentWindow() && $this->hasEmbeddingRule($rules)) {
					continue;
				}
				$itemsToRemove[$index] = true;
				$stats['processed_items']++;
				continue;
			}
			$deferredRuleIds = $this->ruleIdsNotInGroups($rules, $rulesByProfile);

			$fitsWindow = true;
			foreach ($rulesByProfile as $profileId => $_profileRules) {
				$limit = $this->profileWindowSize($profileId);
				if (($selectedCountsByProfile[$profileId] ?? 0) >= $limit) {
					$fitsWindow = false;
					break;
				}
			}
			if (!$fitsWindow) {
				continue;
			}

			foreach ($rulesByProfile as $profileId => $_profileRules) {
				$selectedCountsByProfile[$profileId] = ($selectedCountsByProfile[$profileId] ?? 0) + 1;
			}

			$selectedIndexes[$index] = true;
			$selectedStates[$index] = [
				'item' => $item,
				'entry' => $entry,
				'descriptor' => $entryDescriptor,
				'rules' => $rules,
				'rules_by_profile' => $rulesByProfile,
				'deferred_rule_ids' => $deferredRuleIds,
				'before_tags' => is_array($entry->tags(false)) ? $entry->tags(false) : [],
			];
		}

		if (count($selectedStates) === 0 && count($itemsToRemove) === 0 && count($retryItemsByIndex) === 0) {
			return ['items' => $items, 'stats' => $stats, 'made_progress' => false];
		}

		$aggregates = [];
		foreach ($selectedStates as $index => $state) {
			$aggregates[$index] = [
				'tags' => $state['before_tags'],
				'mark_read' => false,
				'results' => [],
				'failed_rule_ids' => [],
			];
		}

		$batchSequence = 0;
		foreach ($selectedCountsByProfile as $profileId => $windowSize) {
			$profile = $this->profiles->find((string)$profileId);
			if (!is_array($profile) || empty($profile['enabled'])) {
				continue;
			}
			$tasks = [];
			foreach ($selectedStates as $index => $state) {
				if (!isset($state['rules_by_profile'][$profileId])) {
					continue;
				}
				$tasks[] = [
					'task_id' => (string)$index,
					'entry' => $state['entry'],
					'rules' => $state['rules_by_profile'][$profileId],
				];
			}
			if (count($tasks) === 0) {
				continue;
			}

			$startedAt = microtime(true);
			$batchResults = $this->engine->runProfileBatch($profile, $tasks);
			++$batchSequence;
			$failedEntries = 0;
			$aggregateEntries = 0;
			$concurrentEntries = 0;
			$fallbackEntries = 0;
			foreach ($tasks as $task) {
				$taskId = (int)$task['task_id'];
				$result = $batchResults[(string)$taskId] ?? ['tags' => [], 'mark_read' => false, 'results' => [], 'failed_rule_ids' => [], 'transport' => 'concurrent'];
				$aggregates[$taskId]['tags'] = array_values(array_unique(array_merge($aggregates[$taskId]['tags'], $result['tags'] ?? [])));
				$aggregates[$taskId]['mark_read'] = !empty($aggregates[$taskId]['mark_read']) || !empty($result['mark_read']);
				$aggregates[$taskId]['results'] = array_merge($aggregates[$taskId]['results'], $result['results'] ?? []);
				$aggregates[$taskId]['failed_rule_ids'] = array_values(array_unique(array_merge($aggregates[$taskId]['failed_rule_ids'], $result['failed_rule_ids'] ?? [])));
				$aggregates[$taskId]['transport'] = (string)($result['transport'] ?? 'concurrent');
				if (($result['transport'] ?? 'concurrent') === 'aggregate') {
					$aggregateEntries++;
				} elseif (($result['transport'] ?? 'concurrent') === 'fallback_retry') {
					$fallbackEntries++;
				} else {
					$concurrentEntries++;
				}
				if (count($result['failed_rule_ids'] ?? []) > 0) {
					$failedEntries++;
				}
			}

			$this->diagnostics->append([
				'type' => 'queue_batch',
				'profile_id' => $profileId,
				'profile_name' => (string)($profile['name'] ?? $profileId),
				'batch_index' => $batchSequence,
				'window_size' => $windowSize,
				'processed_entries' => count($tasks),
				'aggregate_entries' => $aggregateEntries,
				'aggregate_entry_attempts' => $aggregateEntries,
				'aggregate_requests' => $aggregateEntries > 0 ? 1 : 0,
				'concurrent_entries' => $concurrentEntries,
				'fallback_entries' => $fallbackEntries,
				'failed_entries' => $failedEntries,
				'execution_mode' => $this->diagnosticExecutionMode($aggregateEntries, $concurrentEntries, $fallbackEntries),
				'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
			]);
		}

		$entryDao = FreshRSS_Factory::createEntryDao();
		$retryQueue = [];
		foreach ($selectedStates as $index => $state) {
			$aggregate = $aggregates[$index];
			$persist = ['updated' => false, 'applied_tags' => [], 'failed_tags' => [], 'marked_read' => false, 'failed_read' => false];
			$shouldMarkRead = !empty($aggregate['mark_read']) && !$state['entry']->isRead();
			if (count($aggregate['tags']) > count($state['before_tags']) || $shouldMarkRead) {
				$persist = AutoLabelEntryPersistence::updateTags($entryDao, $state['entry'], $aggregate['tags'], !empty($aggregate['mark_read']));
				if (!empty($persist['updated'])) {
					$stats['updated_entries']++;
					$stats['matched_tags'] += max(0, count($persist['applied_tags']) - count($state['before_tags']));
					$this->notifications->recordMatches(
						$state['entry'],
						is_array($state['before_tags'] ?? null) ? $state['before_tags'] : [],
						$persist,
						is_array($aggregate['results'] ?? null) ? $aggregate['results'] : [],
						'queue'
					);
				}
			}

			$this->diagnostics->append([
				'type' => 'queue_entry',
				'entry_title' => $state['entry']->title(),
				'result' => [
					'tags' => $aggregate['tags'],
					'mark_read' => !empty($aggregate['mark_read']),
					'results' => $aggregate['results'],
					'transport' => $aggregate['transport'] ?? 'concurrent',
				],
				'updated' => !empty($persist['updated']),
				'failed_tags' => $persist['failed_tags'] ?? [],
				'marked_read' => !empty($persist['marked_read']),
				'failed_read' => !empty($persist['failed_read']),
			]);

			$stats['processed_items']++;
			$stats['processed_entries']++;

			$deferredRuleIds = is_array($state['deferred_rule_ids'] ?? null) ? $state['deferred_rule_ids'] : [];
			$retryRuleIds = array_values(array_unique(array_merge($aggregate['failed_rule_ids'], $deferredRuleIds)));
			if (count($retryRuleIds) > 0) {
				$attempts = (int)($state['item']['attempts'] ?? 0) + 1;
				if ($attempts >= 3 && count($deferredRuleIds) > 0) {
					$retryRuleIds = $deferredRuleIds;
				}
				if ($attempts < 3 || count($deferredRuleIds) > 0) {
					$retryItem = $state['item'];
					$retryItem['attempts'] = count($aggregate['failed_rule_ids']) > 0 ? $attempts : (int)($state['item']['attempts'] ?? 0);
					$retryItem['next_attempt_at'] = $now + self::ENTRY_RETRY_DELAY_SECONDS;
					$retryItem['rule_ids'] = $retryRuleIds;
					$retryQueue[] = $retryItem;
					continue;
				}

				$this->diagnostics->append([
					'type' => 'queue_drop',
					'reason' => 'max_retries_reached',
					'item' => $state['item'],
					'failed_rule_ids' => $aggregate['failed_rule_ids'],
				]);
			}
		}

		$newItems = [];
		foreach ($items as $index => $item) {
			if (isset($selectedIndexes[$index]) || isset($itemsToRemove[$index])) {
				continue;
			}
			if (isset($retryItemsByIndex[$index])) {
				$newItems[] = $retryItemsByIndex[$index];
				continue;
			}
			$newItems[] = $item;
		}
		$newItems = array_merge($newItems, $retryQueue);

		return ['items' => $newItems, 'stats' => $stats, 'made_progress' => true];
	}

	/**
	 * @param list<array<string,mixed>> $items
	 * @return array{items:list<array<string,mixed>>,stats:array{processed_items:int,processed_entries:int,updated_entries:int,matched_tags:int},made_progress:bool}
	 */
	private function processConcurrentBackfillPass(array $items, int $itemBudget, ?int $maxBackfillEntries = null): array {
		$stats = [
			'processed_items' => 0,
			'processed_entries' => 0,
			'updated_entries' => 0,
			'matched_tags' => 0,
		];
		if ($itemBudget <= 0) {
			return ['items' => $items, 'stats' => $stats, 'made_progress' => false];
		}

		foreach ($items as $index => $item) {
			if (($item['type'] ?? '') !== 'backfill') {
				continue;
			}

			$rules = $this->rulesForItem($item);
			if (count($rules) === 0) {
				$newItems = $items;
				unset($newItems[$index]);
				$stats['processed_items'] = 1;
				return ['items' => array_values($newItems), 'stats' => $stats, 'made_progress' => true];
			}

			$state = is_array($item['state'] ?? null) ? $item['state'] : [];
			$result = $this->backfill->processJobSlice($rules, $state, $maxBackfillEntries);
			$item['state'] = $result['state'];
			$stats['processed_entries'] = (int)($result['state']['processed'] ?? 0) - (int)($state['processed'] ?? 0);
			$stats['updated_entries'] = (int)($result['state']['updated'] ?? 0) - (int)($state['updated'] ?? 0);
			$stats['matched_tags'] = (int)($result['state']['matched_tags'] ?? 0) - (int)($state['matched_tags'] ?? 0);
			$stats['processed_items'] = !empty($result['deferred']) ? 0 : 1;

			$newItems = $items;
			if (!empty($result['finished'])) {
				$this->diagnostics->append([
					'type' => 'backfill',
					'stats' => $this->diagnosticBackfillState($result['state']),
				]);
				unset($newItems[$index]);
			} else {
				$newItems[$index] = $item;
			}

			return ['items' => array_values($newItems), 'stats' => $stats, 'made_progress' => $stats['processed_items'] > 0 || $stats['processed_entries'] > 0];
		}

		return ['items' => $items, 'stats' => $stats, 'made_progress' => false];
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array{keep:bool,item?:array<string,mixed>,processed_items:int,processed_entries:int,updated_entries:int,matched_tags:int}
	 */
	private function processEntryItem(array $item): array {
		$now = time();
		if ((int)($item['next_attempt_at'] ?? 0) > $now) {
			return ['keep' => true, 'item' => $item, 'processed_items' => 0, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
		}

		$rules = $this->rulesForItem($item);
		if (count($rules) === 0) {
			return ['keep' => false, 'processed_items' => 1, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
		}

		$profiles = $this->profilesForRules($rules);
		if (count($profiles) === 0) {
			return ['keep' => false, 'processed_items' => 1, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
		}
		if (!$this->hasAvailableCapacity($profiles)) {
			return ['keep' => true, 'item' => $item, 'processed_items' => 0, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
		}

		$entryDescriptor = is_array($item['entry'] ?? null) ? $item['entry'] : [];
		$entry = $this->resolveQueuedEntry($entryDescriptor);
		if (!$entry instanceof FreshRSS_Entry) {
			$item['attempts'] = (int)($item['attempts'] ?? 0) + 1;
			$item['next_attempt_at'] = $now + self::ENTRY_RETRY_DELAY_SECONDS;
			$isStale = $this->entryDescriptorDate($entryDescriptor) < ($now - self::ENTRY_STALE_AFTER_SECONDS);
			if ($item['attempts'] >= self::MAX_ENTRY_RESOLVE_ATTEMPTS || $isStale) {
				$this->diagnostics->append([
					'type' => 'queue_drop',
					'reason' => 'entry_not_found',
					'item' => $item,
				]);
				return ['keep' => false, 'processed_items' => 1, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
			}

			return ['keep' => true, 'item' => $item, 'processed_items' => 0, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
		}

		$beforeTags = is_array($entry->tags(false)) ? $entry->tags(false) : [];
		$result = $this->engine->runRules($entry, $rules, false);
		$afterTags = $result['tags'];
		$updatedEntries = 0;
		$matchedTags = 0;
		$failedTags = [];
		$markedRead = false;
		$failedRead = false;
		$shouldMarkRead = !empty($result['mark_read']) && !$entry->isRead();
		if (count($afterTags) > count($beforeTags) || $shouldMarkRead) {
			$entryDao = FreshRSS_Factory::createEntryDao();
			$persist = AutoLabelEntryPersistence::updateTags($entryDao, $entry, $afterTags, !empty($result['mark_read']));
			$failedTags = $persist['failed_tags'];
			$markedRead = !empty($persist['marked_read']);
			$failedRead = !empty($persist['failed_read']);
			if ($persist['updated']) {
				$updatedEntries = 1;
				$matchedTags = max(0, count($persist['applied_tags']) - count($beforeTags));
				$this->notifications->recordMatches($entry, $beforeTags, $persist, is_array($result['results'] ?? null) ? $result['results'] : [], 'queue');
			}
		}

		$this->diagnostics->append([
			'type' => 'queue_entry',
			'entry_title' => $entry->title(),
			'result' => $result,
			'updated' => $updatedEntries === 1,
			'failed_tags' => $failedTags,
			'marked_read' => $markedRead,
			'failed_read' => $failedRead,
		]);

		return [
			'keep' => false,
			'processed_items' => 1,
			'processed_entries' => 1,
			'updated_entries' => $updatedEntries,
			'matched_tags' => $matchedTags,
		];
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array{keep:bool,item?:array<string,mixed>,processed_items:int,processed_entries:int,updated_entries:int,matched_tags:int}
	 */
	private function processBackfillItem(array $item, ?int $maxBackfillEntries = null): array {
		$rules = $this->rulesForItem($item);
		if (count($rules) === 0) {
			return ['keep' => false, 'processed_items' => 1, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
		}

		$profiles = $this->profilesForRules($rules);
		if (count($profiles) === 0) {
			return ['keep' => false, 'processed_items' => 1, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
		}
		if (!$this->hasAvailableCapacity($profiles)) {
			return ['keep' => true, 'item' => $item, 'processed_items' => 0, 'processed_entries' => 0, 'updated_entries' => 0, 'matched_tags' => 0];
		}

		$state = is_array($item['state'] ?? null) ? $item['state'] : [];
		$result = $this->backfill->processJobSlice($rules, $state, $maxBackfillEntries);
		$item['state'] = $result['state'];

		if (!empty($result['finished'])) {
			$this->diagnostics->append([
				'type' => 'backfill',
				'stats' => $this->diagnosticBackfillState($result['state']),
			]);
			return [
				'keep' => false,
				'processed_items' => 1,
				'processed_entries' => (int)($result['state']['processed'] ?? 0) - (int)($state['processed'] ?? 0),
				'updated_entries' => (int)($result['state']['updated'] ?? 0) - (int)($state['updated'] ?? 0),
				'matched_tags' => (int)($result['state']['matched_tags'] ?? 0) - (int)($state['matched_tags'] ?? 0),
			];
		}

		return [
			'keep' => true,
			'item' => $item,
			'processed_items' => !empty($result['deferred']) ? 0 : 1,
			'processed_entries' => (int)($result['state']['processed'] ?? 0) - (int)($state['processed'] ?? 0),
			'updated_entries' => (int)($result['state']['updated'] ?? 0) - (int)($state['updated'] ?? 0),
			'matched_tags' => (int)($result['state']['matched_tags'] ?? 0) - (int)($state['matched_tags'] ?? 0),
		];
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	private function diagnosticBackfillState(array $state): array {
		unset($state['aggregate_entry_keys']);
		return $state;
	}

	/**
	 * @param array<string,mixed> $item
	 * @return list<array<string,mixed>>
	 */
	private function rulesForItem(array $item): array {
		$ruleIds = is_array($item['rule_ids'] ?? null) ? $item['rule_ids'] : [];
		if (count($ruleIds) === 0) {
			return $this->rules->enabled();
		}

		$rules = [];
		foreach ($ruleIds as $ruleId) {
			$rule = $this->rules->find((string)$ruleId);
			if (is_array($rule)) {
				$rules[] = $rule;
			}
		}
		return $rules;
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @return list<array<string,mixed>>
	 */
	private function profilesForRules(array $rules): array {
		$profiles = [];
		foreach ($rules as $rule) {
			$profileId = (string)($rule['profile_id'] ?? '');
			if ($profileId === '' || isset($profiles[$profileId])) {
				continue;
			}
			$profile = $this->profiles->find($profileId);
			if (is_array($profile) && !empty($profile['enabled'])) {
				$profiles[$profileId] = $profile;
			}
		}
		return array_values($profiles);
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @return array<string,list<array<string,mixed>>>
	 */
	private function groupRulesByProfile(array $rules): array {
		$grouped = [];
		foreach ($rules as $rule) {
			if (!($rule['enabled'] ?? false)) {
				continue;
			}
			$profileId = trim((string)($rule['profile_id'] ?? ''));
			if ($profileId === '') {
				continue;
			}
			$profile = $this->profiles->find($profileId);
			if (!is_array($profile) || empty($profile['enabled'])) {
				continue;
			}
			if ((string)($rule['mode'] ?? '') === 'embedding' && !$this->engine->supportsConcurrentWindow()) {
				continue;
			}
			$grouped[$profileId][] = $rule;
		}

		return $grouped;
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 */
	private function hasEmbeddingRule(array $rules): bool {
		foreach ($rules as $rule) {
			if ((string)($rule['mode'] ?? '') === 'embedding') {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @param array<string,list<array<string,mixed>>> $rulesByProfile
	 * @return list<string>
	 */
	private function ruleIdsNotInGroups(array $rules, array $rulesByProfile): array {
		$runnable = [];
		foreach ($rulesByProfile as $groupRules) {
			foreach ($groupRules as $rule) {
				$runnable[(string)$rule['id']] = true;
			}
		}

		$deferred = [];
		foreach ($rules as $rule) {
			$ruleId = (string)($rule['id'] ?? '');
			if ($ruleId !== '' && !isset($runnable[$ruleId])) {
				$deferred[$ruleId] = $ruleId;
			}
		}
		return array_values($deferred);
	}

	private function profileWindowSize(string $profileId): int {
		$profile = $this->profiles->find($profileId);
		if (!is_array($profile)) {
			return AutoLabelSystemProfileRepository::DEFAULT_BATCH_SIZE;
		}

		return AutoLabelSystemProfileRepository::normalizeBatchSize((int)($profile['batch_size'] ?? AutoLabelSystemProfileRepository::DEFAULT_BATCH_SIZE));
	}

	private function diagnosticExecutionMode(int $aggregateEntries, int $concurrentEntries, int $fallbackEntries): string {
		$modes = 0;
		$modes += $aggregateEntries > 0 ? 1 : 0;
		$modes += $concurrentEntries > 0 ? 1 : 0;
		$modes += $fallbackEntries > 0 ? 1 : 0;
		if ($modes > 1) {
			return 'mixed';
		}
		if ($aggregateEntries > 0) {
			return 'aggregate';
		}
		if ($fallbackEntries > 0) {
			return 'fallback_retry';
		}
		return 'concurrent';
	}

	/**
	 * @param list<array<string,mixed>> $profiles
	 */
	private function hasAvailableCapacity(array $profiles): bool {
		if (count($profiles) === 0) {
			return false;
		}

		foreach ($profiles as $profile) {
			if (AutoLabelRuntimeBatchGate::hasCapacity($profile)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $descriptor
	 */
	private function resolveQueuedEntry(array $descriptor): ?FreshRSS_Entry {
		$entryDao = FreshRSS_Factory::createEntryDao();
		$entryId = (int)($descriptor['entry_id'] ?? 0);
		if ($entryId > 0 && method_exists($entryDao, 'searchById')) {
			$entry = $entryDao->searchById((string)$entryId);
			if ($entry instanceof FreshRSS_Entry) {
				return $entry;
			}
		}

		$feedId = (int)($descriptor['feed_id'] ?? 0);
		$guid = trim((string)($descriptor['guid'] ?? ''));
		if ($feedId > 0 && $guid !== '' && method_exists($entryDao, 'searchByGuid')) {
			$entry = $entryDao->searchByGuid($feedId, $guid);
			if ($entry instanceof FreshRSS_Entry) {
				return $entry;
			}
		}

		for ($offset = 0; $offset < self::ENTRY_SCAN_LIMIT; $offset += self::ENTRY_SCAN_BATCH_SIZE) {
			$entries = $entryDao->listWhere('a', 0, FreshRSS_Entry::STATE_ALL, null, '0', '0', 'date', 'DESC', '0', [], self::ENTRY_SCAN_BATCH_SIZE, $offset, 'id', 'DESC');
			if (!is_iterable($entries)) {
				return null;
			}

			$foundAny = false;
			foreach ($entries as $entry) {
				$foundAny = true;
				if ($this->matchesDescriptor($entry, $descriptor)) {
					return $entry;
				}
			}

			if (!$foundAny) {
				return null;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $descriptor
	 */
	private function matchesDescriptor(FreshRSS_Entry $entry, array $descriptor): bool {
		$entryId = (int)($descriptor['entry_id'] ?? 0);
		if ($entryId > 0 && (int)$entry->id() === $entryId) {
			return true;
		}

		$guid = trim((string)($descriptor['guid'] ?? ''));
		if ($guid !== '' && trim((string)$entry->guid()) === $guid) {
			return true;
		}

		$link = trim((string)($descriptor['link'] ?? ''));
		$title = trim((string)($descriptor['title'] ?? ''));
		$date = (int)($descriptor['date'] ?? 0);
		$feedId = (int)($descriptor['feed_id'] ?? 0);

		if ($link !== '' && trim((string)$entry->link(true)) === $link) {
			if ($title === '' || trim((string)$entry->title()) === $title) {
				return true;
			}
		}

		if ($title !== '' && trim((string)$entry->title()) === $title && $date > 0 && (int)$entry->date(true) === $date) {
			if ($feedId <= 0 || (int)$entry->feedId() === $feedId) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $descriptor
	 */
	private function entryDescriptorDate(array $descriptor): int {
		return max(0, (int)($descriptor['date'] ?? 0));
	}
}
