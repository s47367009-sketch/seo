<?php
/**
 * Plugin Name:       هوش‌سئو | استودیوی سئوی هوشمند وردپرس
 * Plugin URI:        https://github.com/s47367009-sketch/seo
 * Description:       افزونه جامع سئوی فارسی با هوش مصنوعی: تحلیل محتوا، تحقیق کلمات کلیدی بومی، اسکیما، نقشه سایت، ریدایرکت، لینک داخلی، ردیاب رتبه، ایندکس‌بان، بهینه‌سازی برای موتورهای پاسخ (GEO) و اپلیکیشن مستقل تمام‌صفحه.
 * Version:           1.1.1
 * Requires at least: 5.8
 * Requires PHP:      7.3
 * Author:            HooshSEO
 * Author URI:        https://github.com/s47367009-sketch/seo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hoosh-seo
 * Domain Path:       /languages
 *
 * Copyright (C) 2026 HooshSEO
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

defined( 'ABSPATH' ) || exit( 'No direct script access allowed.' );

define( 'HOOSH_SEO_VERSION', '1.1.1' );
define( 'HOOSH_SEO_FILE', __FILE__ );
define( 'HOOSH_SEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'HOOSH_SEO_URL', plugin_dir_url( __FILE__ ) );
define( 'HOOSH_SEO_BASENAME', plugin_basename( __FILE__ ) );
define( 'HOOSH_SEO_MIN_PHP', '7.3' );
define( 'HOOSH_SEO_APP_SLUG', 'hoosh-seo-studio' );

/**
 * PHP version guard. Fails politely instead of a white screen on old hosts.
 */
if ( version_compare( PHP_VERSION, HOOSH_SEO_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'افزونه هوش‌سئو به PHP نسخه %1$s یا بالاتر نیاز دارد. نسخه فعلی سرور شما %2$s است.', 'hoosh-seo' ),
						HOOSH_SEO_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);

	return;
}

require_once HOOSH_SEO_DIR . 'includes/autoloader.php';
\HooshSEO\Autoloader::register();

/**
 * Access the singleton.
 *
 * @return \HooshSEO\Plugin
 */
function hoosh_seo() {
	return \HooshSEO\Plugin::instance();
}

register_activation_hook( __FILE__, array( '\HooshSEO\Activator', 'run' ) );
register_deactivation_hook( __FILE__, array( '\HooshSEO\Deactivator', 'run' ) );

hoosh_seo()->boot();
