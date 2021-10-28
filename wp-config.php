<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the
 * installation. You don't have to use the web site, you can
 * copy this file to "wp-config.php" and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'testwp_db' );

/** MySQL database username */
define( 'DB_USER', 'root' );

/** MySQL database password */
define( 'DB_PASSWORD', 'root' );

/** MySQL hostname */
define( 'DB_HOST', 'localhost' );

/** Database Charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The Database Collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '#imq=&5D}g>bB;XCk#O!H_k,K(v9ET(Lpi]S6?W2I@cG@TP5);u&GaXPH@Ln)`Mw' );
define( 'SECURE_AUTH_KEY',  'T</F^.[g-f8Mjf{,:>A1V]HQ<Om;RxJ-HT1!H;T`m_Ce,sEx0BbkK2.[D2`FHFZ|' );
define( 'LOGGED_IN_KEY',    'TBPcR7CZ@) YI`^Q|S+naY)T-5X|Y97He:js$ev71;3$:;3:}=VKC4=yn+0F6[@i' );
define( 'NONCE_KEY',        '#tUG90o{cKs/up}4_.8zZ5uBZY.Nmb8rm1KKkzdET.q6ipA`0oI(VHf(cc{ZI>U0' );
define( 'AUTH_SALT',        '=a`zex96,*oEz4o)@AW+4hSj1^BT$CgHh9`ur[,jqaw nsCvk(KRJsqs#x%X>_72' );
define( 'SECURE_AUTH_SALT', 'JLG]=]6}]z.@A#TgwtmXyow$iD5;)j^21xfZuF+bwZW>8O$`:^r=;;knKPg gt(=' );
define( 'LOGGED_IN_SALT',   'KlLNUM#r,D5g{O8ytoF2,gatRoA&LB/,M_d ]AhG}VCJ}!{C-<tY3}_pUetSb6p<' );
define( 'NONCE_SALT',       'C2s?}pd9?/ZE*oxsa|[-ET61|b,UkK|VF:@>Y&pD*5 NYhGxd,pA=V0/R)jsP+vG' );

/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
define( 'WP_DEBUG', false );

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
