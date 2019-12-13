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
 * @link https://codex.wordpress.org/Editing_wp-config.php
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'binarysoft' );

/** MySQL database username */
define( 'DB_USER', 'root' );

/** MySQL database password */
define( 'DB_PASSWORD', '' );

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
define( 'AUTH_KEY',         'r!2THPK7]!bRb8jLH#Zi=j)#k$,PQ>F9x=8n]mb%_edd-HcC*#$LnbERI}.|U2AA' );
define( 'SECURE_AUTH_KEY',  'JEMu7Aqz%nu(ku:IAZbO.D|nI&q?QH@U<MprfOA$WI,gu*V +;<I6tWcx9{SS|zb' );
define( 'LOGGED_IN_KEY',    '1t|3Zo?p$ZNp.G^/WFP%uo;IM#>kiwCiEmV,zd@%*=;^(MN|yZ!S1:nrDDS9c?rH' );
define( 'NONCE_KEY',        'e>-{n`inCxMpIbo165mi%@JwPk3dKjgYH#8{d|dL:7WsI6r@y >RZ>Dk~HrG6PWu' );
define( 'AUTH_SALT',        '*Glb]b/1P8ff$P@V~lqpdp78ocRsT*ljlzBPyvTJ!_0xu3$`RfBTs[mMq 3!NBf[' );
define( 'SECURE_AUTH_SALT', 'br.B[MGxz,|A$Z;pQE$#/ XI^I|S2)_MGgh7.4g*mrOt,t?yXnFr(nu/-yi0l Vp' );
define( 'LOGGED_IN_SALT',   'We3BJL#&m6>/+EM8X]{Y(&=lHl1`Yo}`,F`mg.jgW `-SqR1do{@Ht<BH?waM~lr' );
define( 'NONCE_SALT',       'DHaEY;A`]h%h,tmxY$_*S%oa]pfG#CX|Fii)$p3POa!9K`h9|45^!G?+*.M^So/N' );

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
 * visit the Codex.
 *
 * @link https://codex.wordpress.org/Debugging_in_WordPress
 */
define( 'WP_DEBUG', false );

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __FILE__ ) . '/' );
}

/** Sets up WordPress vars and included files. */
require_once( ABSPATH . 'wp-settings.php' );
