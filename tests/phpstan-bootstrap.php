<?php
/**
 * Constants WordPress defines at runtime, for static analysis only.
 *
 * @package RankSphere
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress' own constants.
defined( 'ABSPATH' ) || define( 'ABSPATH', '/tmp/wordpress/' );
defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'ranksphere/ranksphere.php' );
