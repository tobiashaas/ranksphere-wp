<?php
/**
 * Where an SEO plugin keeps the fields.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Seo;

/**
 * One implementation per SEO plugin (docs/SEO-PLUGINS.md): read the raw values – never the
 * rendered ones with resolved variables – and write a partial update the way that plugin expects,
 * including its caches and indexes.
 */
interface Adapter {

	/**
	 * WordPress.org slug of the plugin, "ranksphere" for the plugin's own fields.
	 */
	public function slug(): string;

	/**
	 * The fields this plugin can store (some have no focus keyword in their free version).
	 *
	 * @return list<string>
	 */
	public function supports(): array;

	/**
	 * The raw values.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array{title: ?string, description: ?string, focus_keywords: ?list<string>, canonical: ?string, noindex: ?bool}
	 */
	public function read( int $post_id ): array;

	/**
	 * Writes the given fields (only supported ones); null clears a field.
	 *
	 * @param int                                          $post_id Post ID.
	 * @param array<string, string|bool|list<string>|null> $changes Cleaned changes (SeoFields::changes()).
	 */
	public function write( int $post_id, array $changes ): void;
}
