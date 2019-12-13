<?php

defined( 'ABSPATH' ) or die;

$GLOBALS['processed_terms'] = array();
$GLOBALS['processed_posts'] = array();

require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

/**
 * Add an Import Action, this data is stored for processing after import is done.
 *
 * Each action is sent as an Ajax request and is handled by themify-ajax.php file
 */ 
function themify_add_import_action( $action = '', $data = array() ) {
	global $import_actions;

	if ( ! isset( $import_actions[ $action ] ) ) {
		$import_actions[ $action ] = array();
	}

	$import_actions[ $action ][] = $data;
}

function themify_import_post( $post ) {
	global $processed_posts, $processed_terms;

	if ( ! post_type_exists( $post['post_type'] ) ) {
		return;
	}

	/* Menu items don't have reliable post_title, skip the post_exists check */
	if( $post['post_type'] !== 'nav_menu_item' ) {
		$post_exists = post_exists( $post['post_title'], '', $post['post_date'] );
		if ( $post_exists && get_post_type( $post_exists ) == $post['post_type'] ) {
			$processed_posts[ intval( $post['ID'] ) ] = intval( $post_exists );
			return;
		}
	}

	if( $post['post_type'] == 'nav_menu_item' ) {
		if( ! isset( $post['tax_input']['nav_menu'] ) || ! term_exists( $post['tax_input']['nav_menu'], 'nav_menu' ) ) {
			return;
		}
		$_menu_item_type = $post['meta_input']['_menu_item_type'];
		$_menu_item_object_id = $post['meta_input']['_menu_item_object_id'];

		if ( 'taxonomy' == $_menu_item_type && isset( $processed_terms[ intval( $_menu_item_object_id ) ] ) ) {
			$post['meta_input']['_menu_item_object_id'] = $processed_terms[ intval( $_menu_item_object_id ) ];
		} else if ( 'post_type' == $_menu_item_type && isset( $processed_posts[ intval( $_menu_item_object_id ) ] ) ) {
			$post['meta_input']['_menu_item_object_id'] = $processed_posts[ intval( $_menu_item_object_id ) ];
		} else if ( 'custom' != $_menu_item_type ) {
			// associated object is missing or not imported yet, we'll retry later
			// $missing_menu_items[] = $item;
			return;
		}
	}

	$post_parent = ( $post['post_type'] == 'nav_menu_item' ) ? $post['meta_input']['_menu_item_menu_item_parent'] : (int) $post['post_parent'];
	$post['post_parent'] = 0;
	if ( $post_parent ) {
		// if we already know the parent, map it to the new local ID
		if ( isset( $processed_posts[ $post_parent ] ) ) {
			if( $post['post_type'] == 'nav_menu_item' ) {
				$post['meta_input']['_menu_item_menu_item_parent'] = $processed_posts[ $post_parent ];
			} else {
				$post['post_parent'] = $processed_posts[ $post_parent ];
			}
		}
	}

	/**
	 * for hierarchical taxonomies, IDs must be used so wp_set_post_terms can function properly
	 * convert term slugs to IDs for hierarchical taxonomies
	 */
	if( ! empty( $post['tax_input'] ) ) {
		foreach( $post['tax_input'] as $tax => $terms ) {
			if( is_taxonomy_hierarchical( $tax ) ) {
				$terms = explode( ', ', $terms );
				$post['tax_input'][ $tax ] = array_map( 'themify_get_term_id_by_slug', $terms, array_fill( 0, count( $terms ), $tax ) );
			}
		}
	}

	$post['post_author'] = (int) get_current_user_id();
	$post['post_status'] = 'publish';

	$old_id = $post['ID'];

	unset( $post['ID'] );
	$post_id = wp_insert_post( $post, true );
	if( is_wp_error( $post_id ) ) {
		return false;
	} else {
		$processed_posts[ $old_id ] = $post_id;

		return $post_id;
	}
}

function themify_get_placeholder_image() {
	static $placeholder_image = null;

	if( $placeholder_image == null ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		$upload = wp_upload_bits( $post['post_name'] . '.jpg', null, $wp_filesystem->get_contents( THEMIFY_DIR . '/img/image-placeholder.jpg' ) );

		if ( $info = wp_check_filetype( $upload['file'] ) )
			$post['post_mime_type'] = $info['type'];
		else
			return new WP_Error( 'attachment_processing_error', __( 'Invalid file type', 'themify' ) );

		$post['guid'] = $upload['url'];
		$post_id = wp_insert_attachment( $post, $upload['file'] );
		wp_update_attachment_metadata( $post_id, wp_generate_attachment_metadata( $post_id, $upload['file'] ) );

		$placeholder_image = $post_id;
	}

	return $placeholder_image;
}

function themify_import_term( $term ) {
	global $processed_terms;

	if( $term_id = term_exists( $term['slug'], $term['taxonomy'] ) ) {
		if ( is_array( $term_id ) ) $term_id = $term_id['term_id'];
		if ( isset( $term['term_id'] ) )
			$processed_terms[ intval( $term['term_id'] ) ] = (int) $term_id;
		return (int) $term_id;
	}

	if ( empty( $term['parent'] ) ) {
		$parent = 0;
	} else {
		$parent = term_exists( $processed_terms[ intval( $term['parent'] ) ], $term['taxonomy'] );
		if ( is_array( $parent ) ) $parent = $parent['term_id'];
	}

	$id = wp_insert_term( $term['name'], $term['taxonomy'], array(
		'parent' => $parent,
		'slug' => $term['slug'],
		'description' => $term['description'],
	) );
	if ( ! is_wp_error( $id ) ) {
		if ( isset( $term['term_id'] ) ) {
			// success!
			$processed_terms[ intval($term['term_id']) ] = $id['term_id'];
			if ( isset( $term['thumbnail'] ) ) {
				themify_add_import_action( 'term_thumb', array(
					'id' => $id['term_id'],
					'thumb' => $term['thumbnail'],
				) );
			}
			return $term['term_id'];
		}
	}

	return false;
}

function themify_get_term_id_by_slug( $slug, $tax ) {
	$term = get_term_by( 'slug', $slug, $tax );
	if( $term ) {
		return $term->term_id;
	}

	return false;
}

function themify_undo_import_term( $term ) {
	$term_id = term_exists( $term['slug'], $term['taxonomy'] );
	if ( $term_id ) {
		if ( is_array( $term_id ) ) $term_id = $term_id['term_id'];

		if ( $term_thumbnail = get_term_meta( $term['term_id'], 'thumbnail_id', true ) ) {
			wp_delete_attachment( $term_thumbnail, true );
		}

		if ( isset( $term_id ) ) {
			wp_delete_term( $term_id, $term['taxonomy'] );
		}
	}
}

/**
 * Determine if a post exists based on title, content, and date
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param array $args array of database parameters to check
 * @return int Post ID if post exists, 0 otherwise.
 */
function themify_post_exists( $args = array() ) {
	global $wpdb;

	$query = "SELECT ID FROM $wpdb->posts WHERE 1=1";
	$db_args = array();

	foreach ( $args as $key => $value ) {
		$value = wp_unslash( sanitize_post_field( $key, $value, 0, 'db' ) );
		if( ! empty( $value ) ) {
			$query .= ' AND ' . $key . ' = %s';
			$db_args[] = $value;
		}
	}

	if ( !empty ( $args ) )
		return (int) $wpdb->get_var( $wpdb->prepare($query, $args) );

	return 0;
}

function themify_undo_import_post( $post ) {
	if( $post['post_type'] == 'nav_menu_item' ) {
		$post_exists = themify_post_exists( array(
			'post_name' => $post['post_name'],
			'post_modified' => $post['post_date'],
			'post_type' => 'nav_menu_item',
		) );
	} else {
		$post_exists = post_exists( $post['post_title'], '', $post['post_date'] );
	}
	if( $post_exists && get_post_type( $post_exists ) == $post['post_type'] ) {
		/**
		 * check if the post has been modified, if so leave it be
		 *
		 * NOTE: posts are imported using wp_insert_post() which modifies post_modified field
		 * to be the same as post_date, hence to check if the post has been modified,
		 * the post_modified field is compared against post_date in the original post.
		 */
		if( $post['post_date'] == get_post_field( 'post_modified', $post_exists ) ) {
			// find and remove all post attachments
			$attachments = get_posts( array(
				'post_type' => 'attachment',
				'posts_per_page' => -1,
				'post_parent' => $post_exists,
			) );
			if ( $attachments ) {
				foreach ( $attachments as $attachment ) {
					wp_delete_attachment( $attachment->ID, true );
				}
			}
			wp_delete_post( $post_exists, true ); // true: bypass trash
		}
	}
}

function themify_process_post_import( $post ) {
	if( ERASEDEMO ) {
		themify_undo_import_post( $post );
	} else {
		if ( $id = themify_import_post( $post ) ) {
			if ( defined( 'IMPORT_IMAGES' ) && ! IMPORT_IMAGES ) {
				/* if importing images is disabled and post is supposed to have a thumbnail, create a placeholder image instead */
				if ( isset( $post['thumb'] ) ) { // the post is supposed to have featured image
					$placeholder = themify_get_placeholder_image();
					if( ! is_wp_error( $placeholder ) ) {
						set_post_thumbnail( $id, $placeholder );
					}
				}
			} else {
				if ( isset( $post["thumb"] ) ) {
					themify_add_import_action( 'post_thumb', array(
						'id' => $id,
						'thumb' => $post["thumb"],
					) );
				}
				if ( isset( $post["gallery_shortcode"] ) ) {
					themify_add_import_action( 'gallery_field', array(
						'id' => $id,
						'fields' => $post["gallery_shortcode"],
					) );
				}
				if ( isset( $post["_product_image_gallery"] ) ) {
					themify_add_import_action( 'product_gallery', array(
						'id' => $id,
						'images' => $post["_product_image_gallery"],
					) );
				}
			}
		}
	}
}
$thumbs = array();
function themify_do_demo_import() {
global $import_actions;

	if ( isset( $GLOBALS["ThemifyBuilder_Data_Manager"] ) ) {
		remove_action( "save_post", array( $GLOBALS["ThemifyBuilder_Data_Manager"], "save_builder_text_only"), 10, 3 );
	}
$term = array (
  'term_id' => 3,
  'name' => 'News',
  'slug' => 'news',
  'term_group' => 0,
  'taxonomy' => 'category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 2,
  'name' => 'Main Navigation',
  'slug' => 'main-navigation',
  'term_group' => 0,
  'taxonomy' => 'nav_menu',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$post = array (
  'ID' => 118,
  'post_date' => '2016-12-28 11:07:17',
  'post_date_gmt' => '2016-12-28 11:07:17',
  'post_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Proclivi currit oratio. Polemoni et iam ante Aristoteli ea prima visa sunt, quae paulo ante dixi. Nihil enim iam habes, quod ad corpus referas; Te enim iudicem aequum puto, modo quae dicat ille bene noris. Varietates autem iniurasque fortunae facile veteres philosophorum praeceptis instituta vita superabat. Earum etiam rerum, quas terra gignit, educatio quaedam et perfectio est non dissimilis animantium. Duo Reges: constructio interrete. Quae diligentissime contra Aristonem dicuntur a Chryippo. Te enim iudicem aequum puto, modo quae dicat ille bene noris.

<h3>Latine voluptatem vocant</h3>

At vero eos et accusamus et iusto odio dignissimos ducimus qui blanditiis praesentium voluptatum deleniti atque corrupti quos dolores et quas molestias excepturi sint occaecati cupiditate non provident, similique sunt in culpa qui officia deserunt mollitia animi, id est laborum et dolorum fuga. Et harum quidem rerum facilis est et expedita distinctio. Nam libero tempore, cum soluta nobis est eligendi optio cumque nihil impedit quo minus id quod maxime placeat facere possimus, omnis voluptas assumenda est, omnis dolor repellendus. Temporibus autem quibusdam et aut officiis debitis aut rerum necessitatibus saepe eveniet ut et voluptates repudiandae sint et molestiae non recusandae. Itaque earum rerum hic tenetur a sapiente delectus, ut aut reiciendis voluptatibus maiores alias consequatur aut perferendis doloribus asperiores repellat."

"On the other hand, we denounce with righteous indignation and dislike men who are so beguiled and demoralized by the charms of pleasure of the moment, so blinded by desire, that they cannot foresee the pain and trouble that are bound to ensue; and equal blame belongs to those who fail in their duty through weakness of will, which is the same as saying through shrinking from toil and pain. These cases are perfectly simple and easy to distinguish. In a free hour, when our power of choice is untrammelled and when nothing prevents our being able to do what we like best, every pleasure is to be welcomed and every pain avoided. But in certain circumstances and owing to the claims of duty or the obligations of business it will frequently occur that pleasures have to be repudiated and annoyances accepted. The wise man therefore always holds in these matters to this principle of selection: he rejects pleasures to secure other greater pleasures, or else he endures pains to avoid worse pains."',
  'post_title' => 'Reinventing Professionals: Where is the legal industry headed in 2017?',
  'post_excerpt' => '',
  'post_name' => 'reinventing-professionals-legal-industry-headed-2017',
  'post_modified' => '2016-12-30 06:14:36',
  'post_modified_gmt' => '2016-12-30 06:14:36',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=118',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"j2gv077\\"}],\\"styling\\":[],\\"element_id\\":\\"sn76700\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID439495.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 113,
  'post_date' => '2016-12-18 11:04:58',
  'post_date_gmt' => '2016-12-18 11:04:58',
  'post_content' => 'Si longus, levis; Indicant pueri, in quibus ut in speculis natura cernitur. Nemo igitur esse beatus potest. Haec para/doca illi, nos admirabilia dicamus. Utinam quidem dicerent alium alio beatiorem! Iam ruinas videres.

Ne amores quidem sanctos a sapiente alienos esse arbitrantur. Sin autem eos non probabat, quid attinuit cum iis, quibuscum re concinebat, verbis discrepare? Hoc enim identidem dicitis, non intellegere nos quam dicatis voluptatem. Itaque hic ipse iam pridem est reiectus; Tu vero, inquam, ducas licet, si sequetur; At certe gravius. Illum mallem levares, quo optimum atque humanissimum virum, Cn. Etenim semper illud extra est, quod arte comprehenditur.

Negat esse eam, inquit, propter se expetendam. Sed virtutem ipsam inchoavit, nihil amplius. Similiter sensus, cum accessit ad naturam, tuetur illam quidem, sed etiam se tuetur; Non enim iam stirpis bonum quaeret, sed animalis. Efficiens dici potest. Ergo, si semel tristior effectus est, hilara vita amissa est?

Poterat autem inpune; Etsi qui potest intellegi aut cogitari esse aliquod animal, quod se oderit? Quamquam id quidem licebit iis existimare, qui legerint. Tu enim ista lenius, hic Stoicorum more nos vexat. Quod eo liquidius faciet, si perspexerit rerum inter eas verborumne sit controversia. Apud ceteros autem philosophos, qui quaesivit aliquid, tacet; Omnes enim iucundum motum, quo sensus hilaretur. Quamquam id quidem licebit iis existimare, qui legerint. Sed quia studebat laudi et dignitati, multum in virtute processerat. Primum quid tu dicis breve?',
  'post_title' => 'KWM LLP files notice of intention to appoint administrators',
  'post_excerpt' => '',
  'post_name' => 'kwm-llp-files-notice-intention-appoint-administrators',
  'post_modified' => '2016-12-30 06:14:23',
  'post_modified_gmt' => '2016-12-30 06:14:23',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=113',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"0ywg050\\"}],\\"styling\\":[],\\"element_id\\":\\"w690059\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID13561.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 112,
  'post_date' => '2016-12-03 11:04:47',
  'post_date_gmt' => '2016-12-03 11:04:47',
  'post_content' => 'Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo. Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit, sed quia consequuntur magni dolores eos qui ratione voluptatem sequi nesciunt. Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet, consectetur, adipisci velit, sed quia non numquam eius modi tempora incidunt ut labore et dolore magnam aliquam quaerat voluptatem. Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis suscipit laboriosam, nisi ut aliquid ex ea commodi consequatur? Quis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil molestiae consequatur, vel illum qui dolorem eum fugiat quo voluptas nulla pariatur?

Polemoni et iam ante Aristoteli ea prima visa sunt, quae paulo ante dixi. Nihil enim iam habes, quod ad corpus referas; Te enim iudicem aequum puto, modo quae dicat ille bene noris. Varietates autem iniurasque fortunae facile veteres philosophorum praeceptis instituta vita superabat. Earum etiam rerum, quas terra gignit, educatio quaedam et perfectio est non dissimilis animantium. Duo Reges: constructio interrete. 

Quae diligentissime contra Aristonem dicuntur a Chryippo. Te enim iudicem aequum puto, modo quae dicat ille bene noris. Graece donan, Latine voluptatem vocant. Hoc enim constituto in philosophia constituta sunt omnia. Si enim ad populum me vocas, eum. Si longus, levis; Indicant pueri, in quibus ut in speculis natura cernitur. Nemo igitur esse beatus potest. Haec para/doca illi, nos admirabilia dicamus. Utinam quidem dicerent alium alio beatiorem! Iam ruinas videres.',
  'post_title' => 'The top legal stories of 2016: Do you have others?',
  'post_excerpt' => '',
  'post_name' => 'top-legal-stories-2016-others',
  'post_modified' => '2016-12-30 06:14:08',
  'post_modified_gmt' => '2016-12-30 06:14:08',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=112',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"r0s5424\\"}],\\"styling\\":[],\\"element_id\\":\\"889z049\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID2716.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 109,
  'post_date' => '2016-11-20 11:01:41',
  'post_date_gmt' => '2016-11-20 11:01:41',
  'post_content' => 'Graece donan, Latine voluptatem vocant. Hoc enim constituto in philosophia constituta sunt omnia. Si enim ad populum me vocas, eum. Si longus, levis; Indicant pueri, in quibus ut in speculis natura cernitur. Nemo igitur esse beatus potest. Haec para/doca illi, nos admirabilia dicamus. Utinam quidem dicerent alium alio beatiorem! Iam ruinas videres.

Ne amores quidem sanctos a sapiente alienos esse arbitrantur. Sin autem eos non probabat, quid attinuit cum iis, quibuscum re concinebat, verbis discrepare? Hoc enim identidem dicitis, non intellegere nos quam dicatis voluptatem. Itaque hic ipse iam pridem est reiectus; Tu vero, inquam, ducas licet, si sequetur; At certe gravius. Illum mallem levares, quo optimum atque humanissimum virum, Cn. Etenim semper illud extra est, quod arte comprehenditur.

Negat esse eam, inquit, propter se expetendam. Sed virtutem ipsam inchoavit, nihil amplius. Similiter sensus, cum accessit ad naturam, tuetur illam quidem, sed etiam se tuetur; Non enim iam stirpis bonum quaeret, sed animalis. Efficiens dici potest. Ergo, si semel tristior effectus est, hilara vita amissa est?',
  'post_title' => 'Working Mother is surveying law firms to find the best ones for family flexibility',
  'post_excerpt' => '',
  'post_name' => 'working-mother-surveying-law-firms-find-best-ones-family-flexibility',
  'post_modified' => '2016-12-30 06:13:57',
  'post_modified_gmt' => '2016-12-30 06:13:57',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=109',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"uoa1071\\"}],\\"styling\\":[],\\"element_id\\":\\"kv9q909\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID1158436.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 103,
  'post_date' => '2016-11-05 10:59:14',
  'post_date_gmt' => '2016-11-05 10:59:14',
  'post_content' => 'Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet, consectetur, adipisci velit, sed quia non numquam eius modi tempora incidunt ut labore et dolore magnam aliquam quaerat voluptatem. Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis suscipit laboriosam, nisi ut aliquid ex ea commodi consequatur? Quis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil molestiae consequatur, vel illum qui dolorem eum fugiat quo voluptas nulla pariatur?"


"But I must explain to you how all this mistaken idea of denouncing pleasure and praising pain was born and I will give you a complete account of the system, and expound the actual teachings of the great explorer of the truth, the master-builder of human happiness. No one rejects, dislikes, or avoids pleasure itself, because it is pleasure, but because those who do not know how to pursue pleasure rationally encounter consequences that are extremely painful. Nor again is there anyone who loves or pursues or desires to obtain pain of itself, because it is pain, but because occasionally circumstances occur in which toil and pain can procure him some great pleasure. To take a trivial example, which of us ever undertakes laborious physical exercise, except to obtain some advantage from it? But who has any right to find fault with a man who chooses to enjoy a pleasure that has no annoying consequences, or one who avoids a pain that produces no resultant pleasure?"

"At vero eos et accusamus et iusto odio dignissimos ducimus qui blanditiis praesentium voluptatum deleniti atque corrupti quos dolores et quas molestias excepturi sint occaecati cupiditate non provident, similique sunt in culpa qui officia deserunt mollitia animi, id est laborum et dolorum fuga. Et harum quidem rerum facilis est et expedita distinctio. Nam libero tempore, cum soluta nobis est eligendi optio cumque nihil impedit quo minus id quod maxime placeat facere possimus, omnis voluptas assumenda est, omnis dolor repellendus. Temporibus autem quibusdam et aut officiis debitis aut rerum necessitatibus saepe eveniet ut et voluptates repudiandae sint et molestiae non recusandae. Itaque earum rerum hic tenetur a sapiente delectus, ut aut reiciendis voluptatibus maiores alias consequatur aut perferendis doloribus asperiores repellat."',
  'post_title' => 'New York law does not give copyright protection to older recordings, state top court rules',
  'post_excerpt' => '',
  'post_name' => 'new-york-law-not-give-copyright-protection-older-recordings-state-top-court-rules',
  'post_modified' => '2016-12-30 06:13:22',
  'post_modified_gmt' => '2016-12-30 06:13:22',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=103',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"10mw116\\"}],\\"styling\\":[],\\"element_id\\":\\"o1ys110\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID423176.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 97,
  'post_date' => '2016-10-21 10:54:58',
  'post_date_gmt' => '2016-10-21 10:54:58',
  'post_content' => 'Nihil enim iam habes, quod ad corpus referas; Te enim iudicem aequum puto, modo quae dicat ille bene noris. Varietates autem iniurasque fortunae facile veteres philosophorum praeceptis instituta vita superabat. Earum etiam rerum, quas terra gignit, educatio quaedam et perfectio est non dissimilis animantium. Duo Reges: constructio interrete. Quae diligentissime contra Aristonem dicuntur a Chryippo. Te enim iudicem aequum puto, modo quae dicat ille bene noris.

Graece donan, Latine voluptatem vocant. Hoc enim constituto in philosophia constituta sunt omnia. Si enim ad populum me vocas, eum. Si longus, levis; Indicant pueri, in quibus ut in speculis natura cernitur. Nemo igitur esse beatus potest. Haec para/doca illi, nos admirabilia dicamus. Utinam quidem dicerent alium alio beatiorem! Iam ruinas videres.

Ne amores quidem sanctos a sapiente alienos esse arbitrantur. Sin autem eos non probabat, quid attinuit cum iis, quibuscum re concinebat, verbis discrepare? Hoc enim identidem dicitis, non intellegere nos quam dicatis voluptatem.',
  'post_title' => 'New legal software ‘even better’ than humans',
  'post_excerpt' => '',
  'post_name' => 'new-legal-software-even-better-humans',
  'post_modified' => '2016-12-30 06:12:58',
  'post_modified_gmt' => '2016-12-30 06:12:58',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=97',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"bb2f086\\"}],\\"styling\\":[],\\"element_id\\":\\"q08y760\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID447242.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 93,
  'post_date' => '2016-10-14 10:54:47',
  'post_date_gmt' => '2016-10-14 10:54:47',
  'post_content' => 'Nam libero tempore, cum soluta nobis est eligendi optio cumque nihil impedit quo minus id quod maxime placeat facere possimus, omnis voluptas assumenda est, omnis dolor repellendus. Temporibus autem quibusdam et aut officiis debitis aut rerum necessitatibus saepe eveniet ut et voluptates repudiandae sint et molestiae non recusandae. Itaque earum rerum hic tenetur a sapiente delectus, ut aut reiciendis voluptatibus maiores alias consequatur aut perferendis doloribus asperiores repellat.

Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum."

"Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo. Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit, sed quia consequuntur magni dolores eos qui ratione voluptatem sequi nesciunt. Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet, consectetur, adipisci velit, sed quia non numquam eius modi tempora incidunt ut labore et dolore magnam aliquam quaerat voluptatem. Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis suscipit laboriosam, nisi ut aliquid ex ea commodi consequatur? Quis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil molestiae consequatur, vel illum qui dolorem eum fugiat quo voluptas nulla pariatur?',
  'post_title' => 'Law firm leans on podcasting to evoke ‘authentic’ brand',
  'post_excerpt' => '',
  'post_name' => 'law-firm-leans-podcasting-evoke-authentic-brand',
  'post_modified' => '2016-12-30 06:13:34',
  'post_modified_gmt' => '2016-12-30 06:13:34',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=93',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"ssav006\\"}],\\"styling\\":[],\\"element_id\\":\\"82ma946\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID1240365.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 92,
  'post_date' => '2016-10-09 10:51:50',
  'post_date_gmt' => '2016-10-09 10:51:50',
  'post_content' => 'Nam libero tempore, cum soluta nobis est eligendi optio cumque nihil impedit quo minus id quod maxime placeat facere possimus, omnis voluptas assumenda est, omnis dolor repellendus. Temporibus autem quibusdam et aut officiis debitis aut rerum necessitatibus saepe eveniet ut et voluptates repudiandae sint et molestiae non recusandae. Itaque earum rerum hic tenetur a sapiente delectus, ut aut reiciendis voluptatibus maiores alias consequatur aut perferendis doloribus asperiores repellat.

At vero eos et accusamus et iusto odio dignissimos ducimus qui blanditiis praesentium voluptatum deleniti atque corrupti quos dolores et quas molestias excepturi sint occaecati cupiditate non provident, similique sunt in culpa qui officia deserunt mollitia animi, id est laborum et dolorum fuga. Et harum quidem rerum facilis est et expedita distinctio.

On the other hand, we denounce with righteous indignation and dislike men who are so beguiled and demoralized by the charms of pleasure of the moment, so blinded by desire, that they cannot foresee the pain and trouble that are bound to ensue; and equal blame belongs to those who fail in their duty through weakness of will, which is the same as saying through shrinking from toil and pain. These cases are perfectly simple and easy to distinguish. In a free hour, when our power of choice is untrammelled and when nothing prevents our being able to do what we like best, every pleasure is to be welcomed and every pain avoided. But in certain circumstances and owing to the claims of duty or the obligations of business it will frequently occur that pleasures have to be repudiated and annoyances accepted. The wise man therefore always holds in these matters to this principle of selection: he rejects pleasures to secure other greater pleasures, or else he endures pains to avoid worse pains.',
  'post_title' => 'Was this lawyer-turned-WWII-spy the basis for James Bond?',
  'post_excerpt' => '',
  'post_name' => 'lawyer-turned-wwii-spy-basis-james-bond',
  'post_modified' => '2016-12-30 06:12:28',
  'post_modified_gmt' => '2016-12-30 06:12:28',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=92',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"5ow5106\\"}],\\"styling\\":[],\\"element_id\\":\\"c3mt091\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID394295.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 87,
  'post_date' => '2016-10-04 10:49:39',
  'post_date_gmt' => '2016-10-04 10:49:39',
  'post_content' => 'Graece donan, Latine voluptatem vocant. Hoc enim constituto in philosophia constituta sunt omnia. Si enim ad populum me vocas, eum. Si longus, levis; Indicant pueri, in quibus ut in speculis natura cernitur. Nemo igitur esse beatus potest. Haec para/doca illi, nos admirabilia dicamus. Utinam quidem dicerent alium alio beatiorem! Iam ruinas videres.

Ne amores quidem sanctos a sapiente alienos esse arbitrantur. Sin autem eos non probabat, quid attinuit cum iis, quibuscum re concinebat, verbis discrepare? Hoc enim identidem dicitis, non intellegere nos quam dicatis voluptatem. Itaque hic ipse iam pridem est reiectus; Tu vero, inquam, ducas licet, si sequetur; At certe gravius. Illum mallem levares, quo optimum atque humanissimum virum, Cn. Etenim semper illud extra est, quod arte comprehenditur.

Negat esse eam, inquit, propter se expetendam. Sed virtutem ipsam inchoavit, nihil amplius. Similiter sensus, cum accessit ad naturam, tuetur illam quidem, sed etiam se tuetur; Non enim iam stirpis bonum quaeret, sed animalis. Efficiens dici potest. Ergo, si semel tristior effectus est, hilara vita amissa est?',
  'post_title' => 'Georgia lawyer charged with involuntary manslaughter in the shooting death of his wife',
  'post_excerpt' => '',
  'post_name' => 'georgia-lawyer-charged-involuntary-manslaughter-shooting-death-wife',
  'post_modified' => '2016-12-30 06:12:16',
  'post_modified_gmt' => '2016-12-30 06:12:16',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=87',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"ep4e474\\"}],\\"styling\\":[],\\"element_id\\":\\"mpw4804\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID22996.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 85,
  'post_date' => '2016-10-01 10:49:36',
  'post_date_gmt' => '2016-10-01 10:49:36',
  'post_content' => 'Hoc enim identidem dicitis, non intellegere nos quam dicatis voluptatem. Itaque hic ipse iam pridem est reiectus; Tu vero, inquam, ducas licet, si sequetur; At certe gravius. Illum mallem levares, quo optimum atque humanissimum virum, Cn. Etenim semper illud extra est, quod arte comprehenditur.

Negat esse eam, inquit, propter se expetendam. Sed virtutem ipsam inchoavit, nihil amplius. Similiter sensus, cum accessit ad naturam, tuetur illam quidem, sed etiam se tuetur; Non enim iam stirpis bonum quaeret, sed animalis. Efficiens dici potest. Ergo, si semel tristior effectus est, hilara vita amissa est?

Poterat autem inpune; Etsi qui potest intellegi aut cogitari esse aliquod animal, quod se oderit? Quamquam id quidem licebit iis existimare, qui legerint. Tu enim ista lenius, hic Stoicorum more nos vexat. Quod eo liquidius faciet, si perspexerit rerum inter eas verborumne sit controversia. Apud ceteros autem philosophos, qui quaesivit aliquid, tacet; Omnes enim iucundum motum, quo sensus hilaretur. Quamquam id quidem licebit iis existimare, qui legerint. Sed quia studebat laudi et dignitati, multum in virtute processerat. Primum quid tu dicis breve?',
  'post_title' => 'Students file $5 million class action against Charlotte School of Law',
  'post_excerpt' => '',
  'post_name' => 'students-file-5-million-class-action-charlotte-school-law',
  'post_modified' => '2016-12-30 06:12:05',
  'post_modified_gmt' => '2016-12-30 06:12:05',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=85',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"zl8r705\\"}],\\"styling\\":[],\\"element_id\\":\\"4zjj771\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID1358597.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 79,
  'post_date' => '2016-09-29 10:35:24',
  'post_date_gmt' => '2016-09-29 10:35:24',
  'post_content' => 'Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.

Nam libero tempore, cum soluta nobis est eligendi optio cumque nihil impedit quo minus id quod maxime placeat facere possimus, omnis voluptas assumenda est, omnis dolor repellendus. Temporibus autem quibusdam et aut officiis debitis aut rerum necessitatibus saepe eveniet ut et voluptates repudiandae sint et molestiae non recusandae. Itaque earum rerum hic tenetur a sapiente delectus, ut aut reiciendis voluptatibus maiores alias consequatur aut perferendis doloribus asperiores repellat.

At vero eos et accusamus et iusto odio dignissimos ducimus qui blanditiis praesentium voluptatum deleniti atque corrupti quos dolores et quas molestias excepturi sint occaecati cupiditate non provident, similique sunt in culpa qui officia deserunt mollitia animi, id est laborum et dolorum fuga. Et harum quidem rerum facilis est et expedita distinctio. ',
  'post_title' => 'Revealed: The biggest legal teams in the FTSE 100',
  'post_excerpt' => '',
  'post_name' => 'revealed-biggest-legal-teams-ftse-100',
  'post_modified' => '2016-12-30 06:11:57',
  'post_modified_gmt' => '2016-12-30 06:11:57',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=79',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"84r5779\\"}],\\"styling\\":[],\\"element_id\\":\\"wrnz402\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID1267529.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 80,
  'post_date' => '2016-09-26 10:34:07',
  'post_date_gmt' => '2016-09-26 10:34:07',
  'post_content' => 'Varietates autem iniurasque fortunae facile veteres philosophorum praeceptis instituta vita superabat. Earum etiam rerum, quas terra gignit, educatio quaedam et perfectio est non dissimilis animantium. Duo Reges: constructio interrete. Quae diligentissime contra Aristonem dicuntur a Chryippo. Te enim iudicem aequum puto, modo quae dicat ille bene noris.

Ne amores quidem sanctos a sapiente alienos esse arbitrantur. Sin autem eos non probabat, quid attinuit cum iis, quibuscum re concinebat, verbis discrepare? Hoc enim identidem dicitis, non intellegere nos quam dicatis voluptatem. Itaque hic ipse iam pridem est reiectus; Tu vero, inquam, ducas licet, si sequetur; At certe gravius. Illum mallem levares, quo optimum atque humanissimum virum, Cn. Etenim semper illud extra est, quod arte comprehenditur.

Graece donan, Latine voluptatem vocant. Hoc enim constituto in philosophia constituta sunt omnia. Si enim ad populum me vocas, eum. Si longus, levis; Indicant pueri, in quibus ut in speculis natura cernitur. Nemo igitur esse beatus potest. Haec para/doca illi, nos admirabilia dicamus. Utinam quidem dicerent alium alio beatiorem! Iam ruinas videres.

',
  'post_title' => 'NewLaw firm claims new heights with cloud platform',
  'post_excerpt' => '',
  'post_name' => 'newlaw-firm-claims-new-heights-cloud-platform',
  'post_modified' => '2016-12-30 06:11:47',
  'post_modified_gmt' => '2016-12-30 06:11:47',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=80',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"nfp5696\\"}],\\"styling\\":[],\\"element_id\\":\\"mcwm330\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID1195579.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 76,
  'post_date' => '2016-09-22 10:30:50',
  'post_date_gmt' => '2016-09-22 10:30:50',
  'post_content' => 'Earum etiam rerum, quas terra gignit, educatio quaedam et perfectio est non dissimilis animantium. Duo Reges: constructio interrete. Quae diligentissime contra Aristonem dicuntur a Chryippo. Te enim iudicem aequum puto, modo quae dicat ille bene noris.

Graece donan, Latine voluptatem vocant. Hoc enim constituto in philosophia constituta sunt omnia. Si enim ad populum me vocas, eum. Si longus, levis; Indicant pueri, in quibus ut in speculis natura cernitur. Nemo igitur esse beatus potest. Haec para/doca illi, nos admirabilia dicamus. Utinam quidem dicerent alium alio beatiorem! Iam ruinas videres.

Ne amores quidem sanctos a sapiente alienos esse arbitrantur. Sin autem eos non probabat, quid attinuit cum iis, quibuscum re concinebat, verbis discrepare? Hoc enim identidem dicitis, non intellegere nos quam dicatis voluptatem. Itaque hic ipse iam pridem est reiectus; Tu vero, inquam, ducas licet, si sequetur; At certe gravius. Illum mallem levares, quo optimum atque humanissimum virum, Cn. Etenim semper illud extra est, quod arte comprehenditur.',
  'post_title' => 'Google UK legal chief exits for tech start-up',
  'post_excerpt' => '',
  'post_name' => 'google-uk-legal-chief-exits-tech-start',
  'post_modified' => '2016-12-30 06:11:37',
  'post_modified_gmt' => '2016-12-30 06:11:37',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=76',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"6hmu030\\"}],\\"styling\\":[],\\"element_id\\":\\"bips399\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID2717.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 72,
  'post_date' => '2016-09-20 10:12:22',
  'post_date_gmt' => '2016-09-20 10:12:22',
  'post_content' => 'Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo. Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit, sed quia consequuntur magni dolores eos qui ratione voluptatem sequi nesciunt. 

Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet, consectetur, adipisci velit, sed quia non numquam eius modi tempora incidunt ut labore et dolore magnam aliquam quaerat voluptatem. Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis suscipit laboriosam, nisi ut aliquid ex ea commodi consequatur? Quis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil molestiae consequatur, vel illum qui dolorem eum fugiat quo voluptas nulla pariatur?

At vero eos et accusamus et iusto odio dignissimos ducimus qui blanditiis praesentium voluptatum deleniti atque corrupti quos dolores et quas molestias excepturi sint occaecati cupiditate non provident, similique sunt in culpa qui officia deserunt mollitia animi, id est laborum et dolorum fuga. Et harum quidem rerum facilis est et expedita distinctio. Nam libero tempore, cum soluta nobis est eligendi optio cumque nihil impedit quo minus id quod maxime placeat facere possimus, omnis voluptas assumenda est, omnis dolor repellendus. Temporibus autem quibusdam et aut officiis debitis aut rerum necessitatibus saepe eveniet ut et voluptates repudiandae sint et molestiae non recusandae. Itaque earum rerum hic tenetur a sapiente delectus, ut aut reiciendis voluptatibus maiores alias consequatur aut perferendis doloribus asperiores repellat.',
  'post_title' => 'Australia law firm bosses say Brexit will benefit their businesses',
  'post_excerpt' => '',
  'post_name' => 'australia-law-firm-bosses-say-brexit-will-benefit-businesses',
  'post_modified' => '2016-12-30 06:10:39',
  'post_modified_gmt' => '2016-12-30 06:10:39',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=72',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"kol8008\\"}],\\"styling\\":[],\\"element_id\\":\\"lcbd009\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID1265751.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 71,
  'post_date' => '2016-09-15 10:09:32',
  'post_date_gmt' => '2016-09-15 10:09:32',
  'post_content' => 'Poterat autem inpune; Etsi qui potest intellegi aut cogitari esse aliquod animal, quod se oderit? Quamquam id quidem licebit iis existimare, qui legerint. Tu enim ista lenius, hic Stoicorum more nos vexat. Quod eo liquidius faciet, si perspexerit rerum inter eas verborumne sit controversia. Apud ceteros autem philosophos, qui quaesivit aliquid, tacet; Omnes enim iucundum motum, quo sensus hilaretur. Quamquam id quidem licebit iis existimare, qui legerint. Sed quia studebat laudi et dignitati, multum in virtute processerat. Primum quid tu dicis breve?

<h3>Haec para/doca illi, nos admirabilia dicamus.</h3>
Ne amores quidem sanctos a sapiente alienos esse arbitrantur. Sin autem eos non probabat, quid attinuit cum iis, quibuscum re concinebat, verbis discrepare? Hoc enim identidem dicitis, non intellegere nos quam dicatis voluptatem. Itaque hic ipse iam pridem est reiectus; Tu vero, inquam, ducas licet, si sequetur; At certe gravius. Illum mallem levares, quo optimum atque humanissimum virum, Cn. Etenim semper illud extra est, quod arte comprehenditur.

Negat esse eam, inquit, propter se expetendam. Sed virtutem ipsam inchoavit, nihil amplius. Similiter sensus, cum accessit ad naturam, tuetur illam quidem, sed etiam se tuetur; Non enim iam stirpis bonum quaeret, sed animalis. Efficiens dici potest. Ergo, si semel tristior effectus est, hilara vita amissa est?',
  'post_title' => 'Law School? Why Waste Your Money? Just “Esquire” Yourself',
  'post_excerpt' => '',
  'post_name' => 'law-school-waste-money-just-esquire',
  'post_modified' => '2016-12-30 06:10:23',
  'post_modified_gmt' => '2016-12-30 06:10:23',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=71',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"ia2p884\\"}],\\"styling\\":[],\\"element_id\\":\\"ikzr448\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID376521.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 66,
  'post_date' => '2016-09-02 10:04:29',
  'post_date_gmt' => '2016-09-02 10:04:29',
  'post_content' => 'At vero eos et accusamus et iusto odio dignissimos ducimus qui blanditiis praesentium voluptatum deleniti atque corrupti quos dolores et quas molestias excepturi sint occaecati cupiditate non provident, similique sunt in culpa qui officia deserunt mollitia animi, id est laborum et dolorum fuga. Et harum quidem rerum facilis est et expedita distinctio.

[caption id="attachment_69" align="alignleft" width="200"]<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID376517-200x300.jpg" alt="" width="200" height="300" class="size-medium wp-image-69" /> Shot of legal books and a wig on top of a table[/caption] Nam libero tempore, cum soluta nobis est eligendi optio cumque nihil impedit quo minus id quod maxime placeat facere possimus, omnis voluptas assumenda est, omnis dolor repellendus. Temporibus autem quibusdam et aut officiis debitis aut rerum necessitatibus saepe eveniet ut et voluptates repudiandae sint et molestiae non recusandae. Itaque earum rerum hic tenetur a sapiente delectus, ut aut reiciendis voluptatibus maiores alias consequatur aut perferendis doloribus asperiores repellat.

Sed virtutem ipsam inchoavit, nihil amplius. Similiter sensus, cum accessit ad naturam, tuetur illam quidem, sed etiam se tuetur; Non enim iam stirpis bonum quaeret, sed animalis. Efficiens dici potest. Ergo, si semel tristior effectus est, hilara vita amissa est?

 Tu enim ista lenius, hic Stoicorum more nos vexat. Quod eo liquidius faciet, si perspexerit rerum inter eas verborumne sit controversia. Apud ceteros autem philosophos, qui quaesivit aliquid, tacet; Omnes enim iucundum motum, quo sensus hilaretur. ',
  'post_title' => 'Breaking Down The Winter Break For 1Ls',
  'post_excerpt' => '',
  'post_name' => 'breaking-winter-break-1ls',
  'post_modified' => '2016-12-30 06:10:07',
  'post_modified_gmt' => '2016-12-30 06:10:07',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=66',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"mcoa900\\"}],\\"styling\\":[],\\"element_id\\":\\"cprs117\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID375436.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 60,
  'post_date' => '2016-08-23 09:27:32',
  'post_date_gmt' => '2016-08-23 09:27:32',
  'post_content' => 'Ne amores quidem sanctos a sapiente alienos esse arbitrantur. Sin autem eos non probabat, quid attinuit cum iis, quibuscum re concinebat, verbis discrepare? Hoc enim identidem dicitis, non intellegere nos quam dicatis voluptatem. Itaque hic ipse iam pridem est reiectus; Tu vero, inquam, ducas licet, si sequetur; At certe gravius. Illum mallem levares, quo optimum atque humanissimum virum, Cn. Etenim semper illud extra est, quod arte comprehenditur.

<h3>Omnes enim iucundum motum, quo sensus hilaretur. </h3>

Negat esse eam, inquit, propter se expetendam. Sed virtutem ipsam inchoavit, nihil amplius. Similiter sensus, cum accessit ad naturam, tuetur illam quidem, sed etiam se tuetur; Non enim iam stirpis bonum quaeret, sed animalis. Efficiens dici potest. Ergo, si semel tristior effectus est, hilara vita amissa est?

<ul>
<li>Tu enim ista lenius</li>
<li>Quod eo liquidius faciet</li>
<li>Apud ceteros autem philosophos</li>
<li>Qui quaesivit aliquid, tacet;</li>
<li>Sed quia studebat laudi et dignitati</li>
</ul>

Poterat autem inpune; Etsi qui potest intellegi aut cogitari esse aliquod animal, quod se oderit? Quamquam id quidem licebit iis existimare, qui legerint. Tu enim ista lenius, hic Stoicorum more nos vexat. Quod eo liquidius faciet, si perspexerit rerum inter eas verborumne sit controversia.',
  'post_title' => 'Reformed hacker offers insight into cyber crime',
  'post_excerpt' => '',
  'post_name' => 'reformed-hacker-offers-insight-cyber-crime',
  'post_modified' => '2016-12-30 06:09:57',
  'post_modified_gmt' => '2016-12-30 06:09:57',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=60',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"83wu886\\"}],\\"styling\\":[],\\"element_id\\":\\"amu0000\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID466583.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 56,
  'post_date' => '2016-08-18 09:23:38',
  'post_date_gmt' => '2016-08-18 09:23:38',
  'post_content' => 'Proclivi currit oratio. Polemoni et iam ante Aristoteli ea prima visa sunt, quae paulo ante dixi. Nihil enim iam habes, quod ad corpus referas; Te enim iudicem aequum puto, modo quae dicat ille bene noris. Varietates autem iniurasque fortunae facile veteres philosophorum praeceptis instituta vita superabat. Earum etiam rerum, quas terra gignit, educatio quaedam et perfectio est non dissimilis animantium. Duo Reges: constructio interrete. Quae diligentissime contra Aristonem dicuntur a Chryippo. Te enim iudicem aequum puto, modo quae dicat ille bene noris.

<h3>Lorem ipsum dolor sit amet, consectetur adipiscing elit.</h3>

Hoc enim constituto in philosophia constituta sunt omnia. Si enim ad populum me vocas, eum. Si longus, levis; Indicant pueri, in quibus ut in speculis natura cernitur. Nemo igitur esse beatus potest. Haec para/doca illi, nos admirabilia dicamus. Utinam quidem dicerent alium alio beatiorem! Iam ruinas videres.

At vero eos et accusamus et iusto odio dignissimos ducimus qui blanditiis praesentium voluptatum deleniti atque corrupti quos dolores et quas molestias excepturi sint occaecati cupiditate non provident, similique sunt in culpa qui officia deserunt mollitia animi, id est laborum et dolorum fuga. ',
  'post_title' => 'Why law firms are finding the disclosure hurdle too high to leap',
  'post_excerpt' => '',
  'post_name' => 'law-firms-finding-disclosure-hurdle-high-leap',
  'post_modified' => '2016-12-30 06:09:44',
  'post_modified_gmt' => '2016-12-30 06:09:44',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=56',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"uny0343\\"}],\\"styling\\":[],\\"element_id\\":\\"5bzf303\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID376518.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 52,
  'post_date' => '2016-08-15 09:19:56',
  'post_date_gmt' => '2016-08-15 09:19:56',
  'post_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.

<h3>Earum etiam rerum, quas terra gignit</h3>

Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo. Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit, sed quia consequuntur magni dolores eos qui ratione voluptatem sequi nesciunt. Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet, consectetur, adipisci velit, sed quia non numquam eius modi tempora incidunt ut labore et dolore magnam aliquam quaerat voluptatem.

Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis suscipit laboriosam, nisi ut aliquid ex ea commodi consequatur? Quis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil molestiae consequatur, vel illum qui dolorem eum fugiat quo voluptas nulla pariatur?',
  'post_title' => 'Cripps boss on keeping lawyers “lean, mean and hungry”',
  'post_excerpt' => '',
  'post_name' => 'cripps-boss-keeping-lawyers-lean-mean-hungry',
  'post_modified' => '2016-12-30 06:09:26',
  'post_modified_gmt' => '2016-12-30 06:09:26',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=52',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"0ids010\\"}],\\"styling\\":[],\\"element_id\\":\\"zy1d840\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID22989.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 102,
  'post_date' => '2016-07-28 10:58:37',
  'post_date_gmt' => '2016-07-28 10:58:37',
  'post_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Proclivi currit oratio. Polemoni et iam ante Aristoteli ea prima visa sunt, quae paulo ante dixi. Nihil enim iam habes, quod ad corpus referas; Te enim iudicem aequum puto, modo quae dicat ille bene noris. 


Ne amores quidem sanctos a sapiente alienos esse arbitrantur. Sin autem eos non probabat, quid attinuit cum iis, quibuscum re concinebat, verbis discrepare? Hoc enim identidem dicitis, non intellegere nos quam dicatis voluptatem. Itaque hic ipse iam pridem est reiectus; Tu vero, inquam, ducas licet, si sequetur; At certe gravius. Illum mallem levares, quo optimum atque humanissimum virum, Cn. Etenim semper illud extra est, quod arte comprehenditur.

Varietates autem iniurasque fortunae facile veteres philosophorum praeceptis instituta vita superabat. Earum etiam rerum, quas terra gignit, educatio quaedam et perfectio est non dissimilis animantium. Duo Reges: constructio interrete. Quae diligentissime contra Aristonem dicuntur a Chryippo. Te enim iudicem aequum puto, modo quae dicat ille bene noris.

Graece donan, Latine voluptatem vocant. Hoc enim constituto in philosophia constituta sunt omnia. Si enim ad populum me vocas, eum. Si longus, levis; Indicant pueri, in quibus ut in speculis natura cernitur. Nemo igitur esse beatus potest. Haec para/doca illi, nos admirabilia dicamus. Utinam quidem dicerent alium alio beatiorem! Iam ruinas videres.
',
  'post_title' => 'Lawyer fined $25,000 for professional misconduct',
  'post_excerpt' => '',
  'post_name' => 'lawyer-fined-25000-professional-misconduct',
  'post_modified' => '2016-12-30 06:09:05',
  'post_modified_gmt' => '2016-12-30 06:09:05',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=102',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[],\\"element_id\\":\\"u18o020\\"}],\\"styling\\":[],\\"element_id\\":\\"z40f151\\"}]',
  ),
  'tax_input' => 
  array (
    'category' => 'news',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-lawyer/files/2016/12/PeopleImages.com-ID375430.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 25,
  'post_date' => '2016-12-28 05:24:23',
  'post_date_gmt' => '2016-12-28 05:24:23',
  'post_content' => '',
  'post_title' => 'About',
  'post_excerpt' => '',
  'post_name' => 'about',
  'post_modified' => '2017-06-22 20:22:13',
  'post_modified_gmt' => '2017-06-22 20:22:13',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?page_id=25',
  'menu_order' => 0,
  'post_type' => 'page',
  'meta_input' => 
  array (
    'page_layout' => 'sidebar-none',
    'content_width' => 'full_width',
    'hide_page_title' => 'yes',
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<h1>About Us<\\\\/h1>\\",\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"180\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"font_family\\":\\"default\\",\\"font_color\\":\\"ffffff_1.00\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_h1\\":\\"default\\",\\"font_size_h1_unit\\":\\"px\\",\\"line_height_h1_unit\\":\\"px\\",\\"font_family_h2\\":\\"default\\",\\"font_size_h2_unit\\":\\"px\\",\\"line_height_h2_unit\\":\\"px\\",\\"font_family_h3\\":\\"default\\",\\"font_size_h3_unit\\":\\"px\\",\\"line_height_h3_unit\\":\\"px\\",\\"font_family_h4\\":\\"default\\",\\"font_size_h4_unit\\":\\"px\\",\\"line_height_h4_unit\\":\\"px\\",\\"font_family_h5\\":\\"default\\",\\"font_size_h5_unit\\":\\"px\\",\\"line_height_h5_unit\\":\\"px\\",\\"font_family_h6\\":\\"default\\",\\"font_size_h6_unit\\":\\"px\\",\\"line_height_h6_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}}],\\"styling\\":[]}],\\"styling\\":{\\"row_width\\":\\"fullwidth\\",\\"row_height\\":\\"\\",\\"custom_css_row\\":\\"\\",\\"row_anchor\\":\\"\\",\\"background_type\\":\\"image\\",\\"background_slider\\":\\"\\",\\"background_slider_size\\":\\"\\",\\"background_slider_mode\\":\\"\\",\\"background_video\\":\\"\\",\\"background_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/aboutbg-1024x319.jpg\\",\\"background_gradient-gradient-type\\":\\"linear\\",\\"background_gradient-gradient-angle\\":\\"180\\",\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_gradient-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\n\\",\\"background_repeat\\":\\"fullcover\\",\\"background_position\\":\\"center-top\\",\\"background_color\\":\\"\\",\\"cover_color-type\\":\\"color\\",\\"cover_color\\":\\"012f5a_0.50\\",\\"cover_gradient-gradient-type\\":\\"linear\\",\\"cover_gradient-gradient-angle\\":\\"180\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_gradient-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\n\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_color_hover\\":\\"\\",\\"cover_gradient_hover-gradient-type\\":\\"linear\\",\\"cover_gradient_hover-gradient-angle\\":\\"180\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_gradient_hover-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\n\\",\\"font_family\\":\\"default\\",\\"font_color\\":\\"\\",\\"font_size\\":\\"\\",\\"font_size_unit\\":\\"px\\",\\"line_height\\":\\"\\",\\"line_height_unit\\":\\"px\\",\\"text_align\\":\\"\\",\\"link_color\\":\\"\\",\\"text_decoration\\":\\"\\",\\"padding_top\\":\\"13\\",\\"padding_top_unit\\":\\"%\\",\\"padding_right\\":\\"\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom\\":\\"11\\",\\"padding_bottom_unit\\":\\"%\\",\\"padding_left\\":\\"\\",\\"padding_left_unit\\":\\"px\\",\\"margin_top\\":\\"\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right\\":\\"\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom\\":\\"\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left\\":\\"\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"border_top_color\\":\\"\\",\\"border_top_width\\":\\"\\",\\"border_top_style\\":\\"solid\\",\\"border_right_color\\":\\"\\",\\"border_right_width\\":\\"\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_color\\":\\"\\",\\"border_bottom_width\\":\\"\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_color\\":\\"\\",\\"border_left_width\\":\\"\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_mobile\\":\\"show\\",\\"animation_effect\\":\\"fadeIn\\",\\"animation_effect_delay\\":\\"\\",\\"animation_effect_repeat\\":\\"\\",\\"custom_parallax_scroll_speed\\":\\"\\",\\"custom_parallax_scroll_zindex\\":\\"\\"}},{\\"row_order\\":\\"1\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col4-2 first\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"mod_settings\\":{\\"style_image\\":\\"image-top\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/about-1.png\\",\\"appearance_image\\":\\"|\\",\\"auto_fullwidth\\":\\"|\\",\\"param_image\\":\\"regular\\",\\"image_zoom_icon\\":\\"|\\",\\"lightbox_size_unit_width\\":\\"pixels\\",\\"lightbox_size_unit_height\\":\\"pixels\\",\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"180\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"font_family\\":\\"default\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_title\\":\\"default\\",\\"font_size_title_unit\\":\\"px\\",\\"line_height_title_unit\\":\\"px\\",\\"font_family_caption\\":\\"default\\",\\"font_size_caption_unit\\":\\"px\\",\\"line_height_caption_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}}],\\"grid_width\\":\\"51.6753\\",\\"styling\\":[]},{\\"column_order\\":\\"1\\",\\"grid_class\\":\\"col4-2 last\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<h2>History of Company<\\\\/h2><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Integer vulputate velit quis iaculis luctus. Vestibulum aliquam laoreet gravida. Pellentesque volutpat ante tortor, ut egestas elit pulvinar sed. Etiam tempus tempus ligula, a auctor nunc sagittis et. Quisque finibus efficitur ligula, a consectetur ipsum interdum id. Nam nec mi vitae dui euismod feugiat ac nec erat.<\\\\/p><p>Vivamus laoreet magna euismod dapibus varius. Donec pharetra libero gravida urna maximus lacinia. Morbi ornare urna laoreet justo malesuada, vel feugiat tortor consectetur.<\\\\/p>\\",\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"180\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"font_family\\":\\"default\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_h1\\":\\"default\\",\\"font_size_h1_unit\\":\\"px\\",\\"line_height_h1_unit\\":\\"px\\",\\"font_family_h2\\":\\"default\\",\\"font_size_h2\\":\\"58\\",\\"font_size_h2_unit\\":\\"px\\",\\"line_height_h2\\":\\"65\\",\\"line_height_h2_unit\\":\\"px\\",\\"font_family_h3\\":\\"default\\",\\"font_size_h3_unit\\":\\"px\\",\\"line_height_h3_unit\\":\\"px\\",\\"font_family_h4\\":\\"default\\",\\"font_size_h4_unit\\":\\"px\\",\\"line_height_h4_unit\\":\\"px\\",\\"font_family_h5\\":\\"default\\",\\"font_size_h5_unit\\":\\"px\\",\\"line_height_h5_unit\\":\\"px\\",\\"font_family_h6\\":\\"default\\",\\"font_size_h6_unit\\":\\"px\\",\\"line_height_h6_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInRight\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_mobile\\":{\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"0\\",\\"font_family\\":\\"default\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"|\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_h1\\":\\"default\\",\\"font_size_h1_unit\\":\\"px\\",\\"line_height_h1_unit\\":\\"px\\",\\"font_family_h2\\":\\"default\\",\\"font_size_h2\\":\\"40\\",\\"font_size_h2_unit\\":\\"px\\",\\"line_height_h2\\":\\"48\\",\\"line_height_h2_unit\\":\\"px\\",\\"font_family_h3\\":\\"default\\",\\"font_size_h3_unit\\":\\"px\\",\\"line_height_h3_unit\\":\\"px\\",\\"font_family_h4\\":\\"default\\",\\"font_size_h4_unit\\":\\"px\\",\\"line_height_h4_unit\\":\\"px\\",\\"font_family_h5\\":\\"default\\",\\"font_size_h5_unit\\":\\"px\\",\\"line_height_h5_unit\\":\\"px\\",\\"font_family_h6\\":\\"default\\",\\"font_size_h6_unit\\":\\"px\\",\\"line_height_h6_unit\\":\\"px\\"}}}],\\"grid_width\\":\\"45.125\\",\\"styling\\":[]}],\\"column_alignment\\":\\"col_align_middle\\",\\"styling\\":{\\"row_width\\":\\"\\",\\"row_height\\":\\"\\",\\"custom_css_row\\":\\"\\",\\"row_anchor\\":\\"\\",\\"background_type\\":\\"image\\",\\"background_slider\\":\\"\\",\\"background_slider_size\\":\\"\\",\\"background_slider_mode\\":\\"\\",\\"background_video\\":\\"\\",\\"background_image\\":\\"\\",\\"background_gradient-gradient-type\\":\\"linear\\",\\"background_gradient-gradient-angle\\":\\"180\\",\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_gradient-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\n\\",\\"background_repeat\\":\\"\\",\\"background_position\\":\\"\\",\\"background_color\\":\\"\\",\\"cover_color-type\\":\\"color\\",\\"cover_color\\":\\"\\",\\"cover_gradient-gradient-type\\":\\"linear\\",\\"cover_gradient-gradient-angle\\":\\"180\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_gradient-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\n\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_color_hover\\":\\"\\",\\"cover_gradient_hover-gradient-type\\":\\"linear\\",\\"cover_gradient_hover-gradient-angle\\":\\"180\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_gradient_hover-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\n\\",\\"font_family\\":\\"default\\",\\"font_color\\":\\"\\",\\"font_size\\":\\"\\",\\"font_size_unit\\":\\"px\\",\\"line_height\\":\\"\\",\\"line_height_unit\\":\\"px\\",\\"text_align\\":\\"\\",\\"link_color\\":\\"\\",\\"text_decoration\\":\\"\\",\\"padding_top\\":\\"5\\",\\"padding_top_unit\\":\\"%\\",\\"padding_right\\":\\"\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom\\":\\"5\\",\\"padding_bottom_unit\\":\\"%\\",\\"padding_left\\":\\"\\",\\"padding_left_unit\\":\\"px\\",\\"margin_top\\":\\"\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right\\":\\"\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom\\":\\"\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left\\":\\"\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"border_top_color\\":\\"\\",\\"border_top_width\\":\\"\\",\\"border_top_style\\":\\"solid\\",\\"border_right_color\\":\\"\\",\\"border_right_width\\":\\"\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_color\\":\\"\\",\\"border_bottom_width\\":\\"\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_color\\":\\"\\",\\"border_left_width\\":\\"\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_mobile\\":\\"show\\",\\"animation_effect\\":\\"\\",\\"animation_effect_delay\\":\\"\\",\\"animation_effect_repeat\\":\\"\\",\\"custom_parallax_scroll_speed\\":\\"\\",\\"custom_parallax_scroll_zindex\\":\\"\\"}},{\\"row_order\\":\\"2\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col4-2 first\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<h2>John Lemo, Our Founder<\\\\/h2><p>He is a Founder in straddled the worlds of science and magic just as they were becoming distinguishable. One of the most learned men of his age, he had been invited to lecture on the geometry of Euclid at the University of Paris while still in his early twenties.<\\\\/p><p>nce and magic just as they were becoming distinguishable. One of the most learned men of his age, he had been invited to lecture ted to lecture on the geometry of Euclid at the University of Paris while still in his early twenties.<\\\\/p>\\",\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"180\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"font_family\\":\\"default\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_h1\\":\\"default\\",\\"font_size_h1_unit\\":\\"px\\",\\"line_height_h1_unit\\":\\"px\\",\\"font_family_h2\\":\\"default\\",\\"font_size_h2\\":\\"58\\",\\"font_size_h2_unit\\":\\"px\\",\\"line_height_h2\\":\\"65\\",\\"line_height_h2_unit\\":\\"px\\",\\"font_family_h3\\":\\"default\\",\\"font_size_h3_unit\\":\\"px\\",\\"line_height_h3_unit\\":\\"px\\",\\"font_family_h4\\":\\"default\\",\\"font_size_h4_unit\\":\\"px\\",\\"line_height_h4_unit\\":\\"px\\",\\"font_family_h5\\":\\"default\\",\\"font_size_h5_unit\\":\\"px\\",\\"line_height_h5_unit\\":\\"px\\",\\"font_family_h6\\":\\"default\\",\\"font_size_h6_unit\\":\\"px\\",\\"line_height_h6_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_mobile\\":{\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"0\\",\\"font_family\\":\\"default\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"|\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_h1\\":\\"default\\",\\"font_size_h1_unit\\":\\"px\\",\\"line_height_h1_unit\\":\\"px\\",\\"font_family_h2\\":\\"default\\",\\"font_size_h2\\":\\"40\\",\\"font_size_h2_unit\\":\\"px\\",\\"line_height_h2\\":\\"48\\",\\"line_height_h2_unit\\":\\"px\\",\\"font_family_h3\\":\\"default\\",\\"font_size_h3_unit\\":\\"px\\",\\"line_height_h3_unit\\":\\"px\\",\\"font_family_h4\\":\\"default\\",\\"font_size_h4_unit\\":\\"px\\",\\"line_height_h4_unit\\":\\"px\\",\\"font_family_h5\\":\\"default\\",\\"font_size_h5_unit\\":\\"px\\",\\"line_height_h5_unit\\":\\"px\\",\\"font_family_h6\\":\\"default\\",\\"font_size_h6_unit\\":\\"px\\",\\"line_height_h6_unit\\":\\"px\\"}}}],\\"grid_width\\":\\"50.1236\\",\\"styling\\":[]},{\\"column_order\\":\\"1\\",\\"grid_class\\":\\"col4-2 last\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"mod_settings\\":{\\"style_image\\":\\"image-top\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/about-2.jpg\\",\\"appearance_image\\":\\"|\\",\\"auto_fullwidth\\":\\"|\\",\\"param_image\\":\\"regular\\",\\"image_zoom_icon\\":\\"|\\",\\"lightbox_size_unit_width\\":\\"pixels\\",\\"lightbox_size_unit_height\\":\\"pixels\\",\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"180\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"font_family\\":\\"default\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_title\\":\\"default\\",\\"font_size_title_unit\\":\\"px\\",\\"line_height_title_unit\\":\\"px\\",\\"font_family_caption\\":\\"default\\",\\"font_size_caption_unit\\":\\"px\\",\\"line_height_caption_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInRight\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}}],\\"grid_width\\":\\"46.6767\\",\\"styling\\":[]}],\\"column_alignment\\":\\"col_align_middle\\",\\"styling\\":{\\"row_width\\":\\"\\",\\"row_height\\":\\"\\",\\"custom_css_row\\":\\"\\",\\"row_anchor\\":\\"\\",\\"background_type\\":\\"image\\",\\"background_slider\\":\\"\\",\\"background_slider_size\\":\\"\\",\\"background_slider_mode\\":\\"\\",\\"background_video\\":\\"\\",\\"background_image\\":\\"\\",\\"background_gradient-gradient-type\\":\\"linear\\",\\"background_gradient-gradient-angle\\":\\"180\\",\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_gradient-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\n\\",\\"background_repeat\\":\\"\\",\\"background_position\\":\\"\\",\\"background_color\\":\\"f1f5f9_1.00\\",\\"cover_color-type\\":\\"color\\",\\"cover_color\\":\\"\\",\\"cover_gradient-gradient-type\\":\\"linear\\",\\"cover_gradient-gradient-angle\\":\\"180\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_gradient-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\n\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_color_hover\\":\\"\\",\\"cover_gradient_hover-gradient-type\\":\\"linear\\",\\"cover_gradient_hover-gradient-angle\\":\\"180\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_gradient_hover-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\n\\",\\"font_family\\":\\"default\\",\\"font_color\\":\\"\\",\\"font_size\\":\\"\\",\\"font_size_unit\\":\\"px\\",\\"line_height\\":\\"\\",\\"line_height_unit\\":\\"px\\",\\"text_align\\":\\"\\",\\"link_color\\":\\"\\",\\"text_decoration\\":\\"\\",\\"padding_top\\":\\"5\\",\\"padding_top_unit\\":\\"%\\",\\"padding_right\\":\\"\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom\\":\\"5\\",\\"padding_bottom_unit\\":\\"%\\",\\"padding_left\\":\\"\\",\\"padding_left_unit\\":\\"px\\",\\"margin_top\\":\\"\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right\\":\\"\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom\\":\\"\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left\\":\\"\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"border_top_color\\":\\"\\",\\"border_top_width\\":\\"\\",\\"border_top_style\\":\\"solid\\",\\"border_right_color\\":\\"\\",\\"border_right_width\\":\\"\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_color\\":\\"\\",\\"border_bottom_width\\":\\"\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_color\\":\\"\\",\\"border_left_width\\":\\"\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_mobile\\":\\"show\\",\\"animation_effect\\":\\"\\",\\"animation_effect_delay\\":\\"\\",\\"animation_effect_repeat\\":\\"\\",\\"custom_parallax_scroll_speed\\":\\"\\",\\"custom_parallax_scroll_zindex\\":\\"\\"}},{\\"row_order\\":\\"3\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<h2>Our Lawyers<\\\\/h2>\\",\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"180\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"font_family\\":\\"default\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom\\":\\"30\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"|\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_h1\\":\\"default\\",\\"font_size_h1_unit\\":\\"px\\",\\"line_height_h1_unit\\":\\"px\\",\\"font_family_h2\\":\\"default\\",\\"font_size_h2_unit\\":\\"px\\",\\"line_height_h2_unit\\":\\"px\\",\\"font_family_h3\\":\\"default\\",\\"font_size_h3_unit\\":\\"px\\",\\"line_height_h3_unit\\":\\"px\\",\\"font_family_h4\\":\\"default\\",\\"font_size_h4_unit\\":\\"px\\",\\"line_height_h4_unit\\":\\"px\\",\\"font_family_h5\\":\\"default\\",\\"font_size_h5_unit\\":\\"px\\",\\"line_height_h5_unit\\":\\"px\\",\\"font_family_h6\\":\\"default\\",\\"font_size_h6_unit\\":\\"px\\",\\"line_height_h6_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"row_order\\":\\"1\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col3-1 first\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/team-1.jpg\\",\\"appearance_image\\":\\"|\\",\\"auto_fullwidth\\":\\"|\\",\\"title_image\\":\\"John Smith\\",\\"param_image\\":\\"regular\\",\\"image_zoom_icon\\":\\"|\\",\\"lightbox_size_unit_width\\":\\"pixels\\",\\"lightbox_size_unit_height\\":\\"pixels\\",\\"caption_image\\":\\"Principle\\",\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"0\\",\\"font_family\\":\\"default\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"|\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_title\\":\\"default\\",\\"font_color_title\\":\\"000000_1.00\\",\\"font_size_title\\":\\"28\\",\\"font_size_title_unit\\":\\"px\\",\\"line_height_title\\":\\"35\\",\\"line_height_title_unit\\":\\"px\\",\\"font_family_caption\\":\\"default\\",\\"font_color_caption\\":\\"303030_1.00\\",\\"font_size_caption\\":\\"18\\",\\"font_size_caption_unit\\":\\"px\\",\\"line_height_caption_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}}],\\"styling\\":[]},{\\"column_order\\":\\"1\\",\\"grid_class\\":\\"col3-1\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/team-2.jpg\\",\\"appearance_image\\":\\"|\\",\\"auto_fullwidth\\":\\"|\\",\\"title_image\\":\\"Audi Farrar\\",\\"param_image\\":\\"regular\\",\\"image_zoom_icon\\":\\"|\\",\\"lightbox_size_unit_width\\":\\"pixels\\",\\"lightbox_size_unit_height\\":\\"pixels\\",\\"caption_image\\":\\"Partner\\",\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"0\\",\\"font_family\\":\\"default\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"|\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_title\\":\\"default\\",\\"font_color_title\\":\\"000000_1.00\\",\\"font_size_title\\":\\"28\\",\\"font_size_title_unit\\":\\"px\\",\\"line_height_title\\":\\"35\\",\\"line_height_title_unit\\":\\"px\\",\\"font_family_caption\\":\\"default\\",\\"font_color_caption\\":\\"303030_1.00\\",\\"font_size_caption\\":\\"18\\",\\"font_size_caption_unit\\":\\"px\\",\\"line_height_caption_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}}],\\"styling\\":[]},{\\"column_order\\":\\"2\\",\\"grid_class\\":\\"col3-1 last\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/team-3.jpg\\",\\"appearance_image\\":\\"|\\",\\"auto_fullwidth\\":\\"|\\",\\"title_image\\":\\"Meggie Penn\\",\\"param_image\\":\\"regular\\",\\"image_zoom_icon\\":\\"|\\",\\"lightbox_size_unit_width\\":\\"pixels\\",\\"lightbox_size_unit_height\\":\\"pixels\\",\\"caption_image\\":\\"Manager\\",\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"0\\",\\"font_family\\":\\"default\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"|\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_title\\":\\"default\\",\\"font_color_title\\":\\"000000_1.00\\",\\"font_size_title\\":\\"28\\",\\"font_size_title_unit\\":\\"px\\",\\"line_height_title\\":\\"35\\",\\"line_height_title_unit\\":\\"px\\",\\"font_family_caption\\":\\"default\\",\\"font_color_caption\\":\\"303030_1.00\\",\\"font_size_caption\\":\\"18\\",\\"font_size_caption_unit\\":\\"px\\",\\"line_height_caption_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}}],\\"styling\\":[]}],\\"column_alignment\\":\\"col_align_bottom\\",\\"styling\\":[]}],\\"styling\\":[]}],\\"styling\\":{\\"row_width\\":\\"\\",\\"row_height\\":\\"\\",\\"custom_css_row\\":\\"lawyers-section\\",\\"row_anchor\\":\\"\\",\\"background_type\\":\\"image\\",\\"background_slider\\":\\"\\",\\"background_slider_size\\":\\"\\",\\"background_slider_mode\\":\\"\\",\\"background_video\\":\\"\\",\\"background_image\\":\\"\\",\\"background_gradient-gradient-type\\":\\"linear\\",\\"background_gradient-gradient-angle\\":\\"180\\",\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_gradient-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\n\\",\\"background_repeat\\":\\"\\",\\"background_position\\":\\"\\",\\"background_color\\":\\"\\",\\"cover_color-type\\":\\"color\\",\\"cover_color\\":\\"\\",\\"cover_gradient-gradient-type\\":\\"linear\\",\\"cover_gradient-gradient-angle\\":\\"180\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_gradient-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\n\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_color_hover\\":\\"\\",\\"cover_gradient_hover-gradient-type\\":\\"linear\\",\\"cover_gradient_hover-gradient-angle\\":\\"180\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_gradient_hover-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\n\\",\\"font_family\\":\\"default\\",\\"font_color\\":\\"\\",\\"font_size\\":\\"\\",\\"font_size_unit\\":\\"px\\",\\"line_height\\":\\"\\",\\"line_height_unit\\":\\"px\\",\\"text_align\\":\\"\\",\\"link_color\\":\\"\\",\\"text_decoration\\":\\"\\",\\"padding_top\\":\\"4\\",\\"padding_top_unit\\":\\"%\\",\\"padding_right\\":\\"\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom\\":\\"4\\",\\"padding_bottom_unit\\":\\"%\\",\\"padding_left\\":\\"\\",\\"padding_left_unit\\":\\"px\\",\\"margin_top\\":\\"\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right\\":\\"\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom\\":\\"\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left\\":\\"\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"border_top_color\\":\\"\\",\\"border_top_width\\":\\"\\",\\"border_top_style\\":\\"solid\\",\\"border_right_color\\":\\"\\",\\"border_right_width\\":\\"\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_color\\":\\"\\",\\"border_bottom_width\\":\\"\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_color\\":\\"\\",\\"border_left_width\\":\\"\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_mobile\\":\\"show\\",\\"animation_effect\\":\\"\\",\\"animation_effect_delay\\":\\"\\",\\"animation_effect_repeat\\":\\"\\",\\"custom_parallax_scroll_speed\\":\\"\\",\\"custom_parallax_scroll_zindex\\":\\"\\"}},{\\"row_order\\":\\"4\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<h2>Careers<\\\\/h2>\\",\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"0\\",\\"font_family\\":\\"default\\",\\"font_color\\":\\"ffffff_1.00\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom\\":\\"10\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"|\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"|\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_h1\\":\\"default\\",\\"font_size_h1_unit\\":\\"px\\",\\"line_height_h1_unit\\":\\"px\\",\\"font_family_h2\\":\\"default\\",\\"font_size_h2_unit\\":\\"px\\",\\"line_height_h2_unit\\":\\"px\\",\\"font_family_h3\\":\\"default\\",\\"font_size_h3_unit\\":\\"px\\",\\"line_height_h3_unit\\":\\"px\\",\\"font_family_h4\\":\\"default\\",\\"font_size_h4_unit\\":\\"px\\",\\"line_height_h4_unit\\":\\"px\\",\\"font_family_h5\\":\\"default\\",\\"font_size_h5_unit\\":\\"px\\",\\"line_height_h5_unit\\":\\"px\\",\\"font_family_h6\\":\\"default\\",\\"font_size_h6_unit\\":\\"px\\",\\"line_height_h6_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"row_order\\":\\"1\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col4-1 first\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<h4>Finance &amp;amp; Securities Lawyer<\\\\/h4>\\\\n<p>+ Best Pellentesque posuere.<br \\\\/>\\\\n+ in we porttitor, nulla<br \\\\/>\\\\n+ vitae posuere iaculis arcu nisl<br \\\\/>\\\\n+ dignissim dolor,<\\\\/p>\\\\n\\",\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"0\\",\\"font_family\\":\\"default\\",\\"font_color\\":\\"ffffff_1.00\\",\\"font_size\\":\\"16\\",\\"font_size_unit\\":\\"px\\",\\"line_height\\":\\"26\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"|\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_h1\\":\\"default\\",\\"font_size_h1_unit\\":\\"px\\",\\"line_height_h1_unit\\":\\"px\\",\\"font_family_h2\\":\\"default\\",\\"font_size_h2_unit\\":\\"px\\",\\"line_height_h2_unit\\":\\"px\\",\\"font_family_h3\\":\\"default\\",\\"font_size_h3_unit\\":\\"px\\",\\"line_height_h3_unit\\":\\"px\\",\\"font_family_h4\\":\\"default\\",\\"font_size_h4\\":\\"28\\",\\"font_size_h4_unit\\":\\"px\\",\\"line_height_h4\\":\\"32\\",\\"line_height_h4_unit\\":\\"px\\",\\"font_family_h5\\":\\"default\\",\\"font_size_h5_unit\\":\\"px\\",\\"line_height_h5_unit\\":\\"px\\",\\"font_family_h6\\":\\"default\\",\\"font_size_h6_unit\\":\\"px\\",\\"line_height_h6_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"mod_name\\":\\"buttons\\",\\"mod_settings\\":{\\"buttons_size\\":\\"large\\",\\"buttons_style\\":\\"squared\\",\\"content_button\\":[{\\"label\\":\\"Read More\\",\\"link\\":\\"#\\",\\"link_options\\":\\"regular\\",\\"lightbox_size_unit_width\\":\\"pixels\\",\\"lightbox_size_unit_height\\":\\"pixels\\",\\"button_color_bg\\":\\"blue\\"}],\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"0\\",\\"font_family\\":\\"default\\",\\"font_size\\":\\"16\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top\\":\\"20\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"|\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"|\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"padding_top_link_unit\\":\\"px\\",\\"padding_right_link_unit\\":\\"px\\",\\"padding_bottom_link_unit\\":\\"px\\",\\"padding_left_link_unit\\":\\"px\\",\\"checkbox_link_padding_apply_all\\":\\"padding\\",\\"checkbox_link_padding_apply_all_padding\\":\\"padding\\",\\"link_margin_top_unit\\":\\"px\\",\\"link_margin_right_unit\\":\\"px\\",\\"link_margin_bottom_unit\\":\\"px\\",\\"link_margin_left_unit\\":\\"px\\",\\"link_checkbox_margin_apply_all\\":\\"margin\\",\\"link_checkbox_margin_apply_all_margin\\":\\"margin\\",\\"link_border_top_style\\":\\"solid\\",\\"link_border_right_style\\":\\"solid\\",\\"link_border_bottom_style\\":\\"solid\\",\\"link_border_left_style\\":\\"solid\\",\\"link_checkbox_border_apply_all\\":\\"border\\",\\"link_checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}}],\\"styling\\":[]},{\\"column_order\\":\\"1\\",\\"grid_class\\":\\"col4-1\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<h4>Business Lawyer<\\\\/h4>\\\\n<p>+ Consectetuer adipiscing elit,<br \\\\/>\\\\n+ Nam liber tempor cum<br \\\\/>\\\\n+ Claritas est etiam processus<br \\\\/>\\\\n+ Mirum est notare quam<\\\\/p>\\\\n\\",\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"0\\",\\"font_family\\":\\"default\\",\\"font_color\\":\\"ffffff_1.00\\",\\"font_size\\":\\"16\\",\\"font_size_unit\\":\\"px\\",\\"line_height\\":\\"26\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"|\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_h1\\":\\"default\\",\\"font_size_h1_unit\\":\\"px\\",\\"line_height_h1_unit\\":\\"px\\",\\"font_family_h2\\":\\"default\\",\\"font_size_h2_unit\\":\\"px\\",\\"line_height_h2_unit\\":\\"px\\",\\"font_family_h3\\":\\"default\\",\\"font_size_h3_unit\\":\\"px\\",\\"line_height_h3_unit\\":\\"px\\",\\"font_family_h4\\":\\"default\\",\\"font_size_h4\\":\\"28\\",\\"font_size_h4_unit\\":\\"px\\",\\"line_height_h4\\":\\"32\\",\\"line_height_h4_unit\\":\\"px\\",\\"font_family_h5\\":\\"default\\",\\"font_size_h5_unit\\":\\"px\\",\\"line_height_h5_unit\\":\\"px\\",\\"font_family_h6\\":\\"default\\",\\"font_size_h6_unit\\":\\"px\\",\\"line_height_h6_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"mod_name\\":\\"buttons\\",\\"mod_settings\\":{\\"buttons_size\\":\\"large\\",\\"buttons_style\\":\\"squared\\",\\"content_button\\":[{\\"label\\":\\"Read More\\",\\"link\\":\\"#\\",\\"link_options\\":\\"regular\\",\\"lightbox_size_unit_width\\":\\"pixels\\",\\"lightbox_size_unit_height\\":\\"pixels\\",\\"button_color_bg\\":\\"blue\\"}],\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"0\\",\\"font_family\\":\\"default\\",\\"font_size\\":\\"16\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top\\":\\"20\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"|\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"|\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"padding_top_link_unit\\":\\"px\\",\\"padding_right_link_unit\\":\\"px\\",\\"padding_bottom_link_unit\\":\\"px\\",\\"padding_left_link_unit\\":\\"px\\",\\"checkbox_link_padding_apply_all\\":\\"padding\\",\\"checkbox_link_padding_apply_all_padding\\":\\"padding\\",\\"link_margin_top_unit\\":\\"px\\",\\"link_margin_right_unit\\":\\"px\\",\\"link_margin_bottom_unit\\":\\"px\\",\\"link_margin_left_unit\\":\\"px\\",\\"link_checkbox_margin_apply_all\\":\\"margin\\",\\"link_checkbox_margin_apply_all_margin\\":\\"margin\\",\\"link_border_top_style\\":\\"solid\\",\\"link_border_right_style\\":\\"solid\\",\\"link_border_bottom_style\\":\\"solid\\",\\"link_border_left_style\\":\\"solid\\",\\"link_checkbox_border_apply_all\\":\\"border\\",\\"link_checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}}],\\"styling\\":[]},{\\"column_order\\":\\"2\\",\\"grid_class\\":\\"col4-1\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<h4>Bankruptcy Lawyer<\\\\/h4>\\\\n<p>+ Typi non habent claritatem<br \\\\/>\\\\n+ Ut wisi enim ad minim<br \\\\/>\\\\n+ Quae cum essent dicta<br \\\\/>\\\\n+ Audax negotium, dicerem<\\\\/p>\\\\n\\",\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"0\\",\\"font_family\\":\\"default\\",\\"font_color\\":\\"ffffff_1.00\\",\\"font_size\\":\\"16\\",\\"font_size_unit\\":\\"px\\",\\"line_height\\":\\"26\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"|\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_h1\\":\\"default\\",\\"font_size_h1_unit\\":\\"px\\",\\"line_height_h1_unit\\":\\"px\\",\\"font_family_h2\\":\\"default\\",\\"font_size_h2_unit\\":\\"px\\",\\"line_height_h2_unit\\":\\"px\\",\\"font_family_h3\\":\\"default\\",\\"font_size_h3_unit\\":\\"px\\",\\"line_height_h3_unit\\":\\"px\\",\\"font_family_h4\\":\\"default\\",\\"font_size_h4\\":\\"28\\",\\"font_size_h4_unit\\":\\"px\\",\\"line_height_h4\\":\\"32\\",\\"line_height_h4_unit\\":\\"px\\",\\"font_family_h5\\":\\"default\\",\\"font_size_h5_unit\\":\\"px\\",\\"line_height_h5_unit\\":\\"px\\",\\"font_family_h6\\":\\"default\\",\\"font_size_h6_unit\\":\\"px\\",\\"line_height_h6_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"mod_name\\":\\"buttons\\",\\"mod_settings\\":{\\"buttons_size\\":\\"large\\",\\"buttons_style\\":\\"squared\\",\\"content_button\\":[{\\"label\\":\\"Read More\\",\\"link\\":\\"#\\",\\"link_options\\":\\"regular\\",\\"lightbox_size_unit_width\\":\\"pixels\\",\\"lightbox_size_unit_height\\":\\"pixels\\",\\"button_color_bg\\":\\"blue\\"}],\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"0\\",\\"font_family\\":\\"default\\",\\"font_size\\":\\"16\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top\\":\\"20\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"|\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"|\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"padding_top_link_unit\\":\\"px\\",\\"padding_right_link_unit\\":\\"px\\",\\"padding_bottom_link_unit\\":\\"px\\",\\"padding_left_link_unit\\":\\"px\\",\\"checkbox_link_padding_apply_all\\":\\"padding\\",\\"checkbox_link_padding_apply_all_padding\\":\\"padding\\",\\"link_margin_top_unit\\":\\"px\\",\\"link_margin_right_unit\\":\\"px\\",\\"link_margin_bottom_unit\\":\\"px\\",\\"link_margin_left_unit\\":\\"px\\",\\"link_checkbox_margin_apply_all\\":\\"margin\\",\\"link_checkbox_margin_apply_all_margin\\":\\"margin\\",\\"link_border_top_style\\":\\"solid\\",\\"link_border_right_style\\":\\"solid\\",\\"link_border_bottom_style\\":\\"solid\\",\\"link_border_left_style\\":\\"solid\\",\\"link_checkbox_border_apply_all\\":\\"border\\",\\"link_checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}}],\\"styling\\":[]},{\\"column_order\\":\\"3\\",\\"grid_class\\":\\"col4-1 last\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<h4 class=\\\\\\\\\\\\\\"tb-menu-title\\\\\\\\\\\\\\">Acquisitions Lawyer<\\\\/h4>\\\\n<p>+  Eadem nunc mea adversum<br \\\\/>\\\\n+  Sed ut perspiciatis unde<br \\\\/>\\\\n+  Nemo enim ipsam voluptatem<br \\\\/>\\\\n+  Ut enim ad minima veniam<\\\\/p>\\\\n\\",\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"0\\",\\"font_family\\":\\"default\\",\\"font_color\\":\\"ffffff_1.00\\",\\"font_size\\":\\"16\\",\\"font_size_unit\\":\\"px\\",\\"line_height\\":\\"26\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"|\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_h1\\":\\"default\\",\\"font_size_h1_unit\\":\\"px\\",\\"line_height_h1_unit\\":\\"px\\",\\"font_family_h2\\":\\"default\\",\\"font_size_h2_unit\\":\\"px\\",\\"line_height_h2_unit\\":\\"px\\",\\"font_family_h3\\":\\"default\\",\\"font_size_h3_unit\\":\\"px\\",\\"line_height_h3_unit\\":\\"px\\",\\"font_family_h4\\":\\"default\\",\\"font_size_h4\\":\\"28\\",\\"font_size_h4_unit\\":\\"px\\",\\"line_height_h4\\":\\"32\\",\\"line_height_h4_unit\\":\\"px\\",\\"font_family_h5\\":\\"default\\",\\"font_size_h5_unit\\":\\"px\\",\\"line_height_h5_unit\\":\\"px\\",\\"font_family_h6\\":\\"default\\",\\"font_size_h6_unit\\":\\"px\\",\\"line_height_h6_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"mod_name\\":\\"buttons\\",\\"mod_settings\\":{\\"buttons_size\\":\\"large\\",\\"buttons_style\\":\\"squared\\",\\"content_button\\":[{\\"label\\":\\"Read More\\",\\"link\\":\\"#\\",\\"link_options\\":\\"regular\\",\\"lightbox_size_unit_width\\":\\"pixels\\",\\"lightbox_size_unit_height\\":\\"pixels\\",\\"button_color_bg\\":\\"blue\\"}],\\"background_image-type\\":\\"image\\",\\"background_image-type_image\\":\\"image\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-type\\":\\"linear\\",\\"background_image-gradient-angle\\":\\"0\\",\\"font_family\\":\\"default\\",\\"font_size\\":\\"16\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top\\":\\"20\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"|\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"|\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"padding_top_link_unit\\":\\"px\\",\\"padding_right_link_unit\\":\\"px\\",\\"padding_bottom_link_unit\\":\\"px\\",\\"padding_left_link_unit\\":\\"px\\",\\"checkbox_link_padding_apply_all\\":\\"padding\\",\\"checkbox_link_padding_apply_all_padding\\":\\"padding\\",\\"link_margin_top_unit\\":\\"px\\",\\"link_margin_right_unit\\":\\"px\\",\\"link_margin_bottom_unit\\":\\"px\\",\\"link_margin_left_unit\\":\\"px\\",\\"link_checkbox_margin_apply_all\\":\\"margin\\",\\"link_checkbox_margin_apply_all_margin\\":\\"margin\\",\\"link_border_top_style\\":\\"solid\\",\\"link_border_right_style\\":\\"solid\\",\\"link_border_bottom_style\\":\\"solid\\",\\"link_border_left_style\\":\\"solid\\",\\"link_checkbox_border_apply_all\\":\\"border\\",\\"link_checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}}],\\"styling\\":[]}],\\"column_alignment\\":\\"\\",\\"styling\\":[]}],\\"styling\\":[]}],\\"styling\\":{\\"row_width\\":\\"\\",\\"row_height\\":\\"\\",\\"custom_css_row\\":\\"\\",\\"row_anchor\\":\\"\\",\\"background_type\\":\\"image\\",\\"background_slider\\":\\"\\",\\"background_slider_size\\":\\"\\",\\"background_slider_mode\\":\\"\\",\\"background_video\\":\\"\\",\\"background_image\\":\\"\\",\\"background_gradient-gradient-type\\":\\"linear\\",\\"background_gradient-gradient-angle\\":\\"180\\",\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_gradient-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\n\\",\\"background_repeat\\":\\"\\",\\"background_position\\":\\"\\",\\"background_color\\":\\"35405d_1.00\\",\\"cover_color-type\\":\\"color\\",\\"cover_color\\":\\"\\",\\"cover_gradient-gradient-type\\":\\"linear\\",\\"cover_gradient-gradient-angle\\":\\"180\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_gradient-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\n\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_color_hover\\":\\"\\",\\"cover_gradient_hover-gradient-type\\":\\"linear\\",\\"cover_gradient_hover-gradient-angle\\":\\"180\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_gradient_hover-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\r\\\\n\\",\\"font_family\\":\\"default\\",\\"font_color\\":\\"\\",\\"font_size\\":\\"\\",\\"font_size_unit\\":\\"px\\",\\"line_height\\":\\"\\",\\"line_height_unit\\":\\"px\\",\\"text_align\\":\\"\\",\\"link_color\\":\\"\\",\\"text_decoration\\":\\"\\",\\"padding_top\\":\\"5\\",\\"padding_top_unit\\":\\"%\\",\\"padding_right\\":\\"\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom\\":\\"10\\",\\"padding_bottom_unit\\":\\"%\\",\\"padding_left\\":\\"\\",\\"padding_left_unit\\":\\"px\\",\\"margin_top\\":\\"\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right\\":\\"\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom\\":\\"\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left\\":\\"\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"border_top_color\\":\\"\\",\\"border_top_width\\":\\"\\",\\"border_top_style\\":\\"solid\\",\\"border_right_color\\":\\"\\",\\"border_right_width\\":\\"\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_color\\":\\"\\",\\"border_bottom_width\\":\\"\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_color\\":\\"\\",\\"border_left_width\\":\\"\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_mobile\\":\\"show\\",\\"animation_effect\\":\\"\\",\\"animation_effect_delay\\":\\"\\",\\"animation_effect_repeat\\":\\"\\",\\"custom_parallax_scroll_speed\\":\\"\\",\\"custom_parallax_scroll_zindex\\":\\"\\"}},{\\"row_order\\":\\"5\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"modules\\":[],\\"styling\\":[]}],\\"styling\\":[]}]',
  ),
  'tax_input' => 
  array (
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 29,
  'post_date' => '2016-12-28 05:25:41',
  'post_date_gmt' => '2016-12-28 05:25:41',
  'post_content' => '<!--themify_builder_static--><h1>Contact Us</h1>
<h3>Before Contacting Us</h3><p>Lorem ipsum dolor sit amet, consectetur adipisici elit, sed eiusmod tempor incidunt ut labore et dolore magna aliqua. Non equidem inv-<br />ideo, miror magis posuere velit aliquet. abore et dolore magna aliqua.<br />Non equidem invideo, miror magis posuere velit</p>
<h3>Our Address</h3>
233 78th Street, New York, NY 10032 youremail@domain.com 1-800-228-3388 Everyday 9:00am - 6:00pm
<h2>Contact</h2>
<form action="https://themify.me/demo/themes/ultra-lawyer/wp-admin/admin-ajax.php" id="contact-0--form" method="post"> <label for="contact-0--contact-name">Name </label> <input type="text" name="contact-name" placeholder="" id="contact-0--contact-name" value="" /> <label for="contact-0--contact-email">Email </label> <input type="text" name="contact-email" placeholder="" id="contact-0--contact-email" value="" /> <label for="contact-0--contact-subject">Subject </label> <input type="text" name="contact-subject" placeholder="" id="contact-0--contact-subject" value="" /> <label for="contact-0--contact-message">Message *</label> <textarea name="contact-message" placeholder="" id="contact-0--contact-message" rows="8" cols="45" required></textarea> <button type="submit"> Send </button> </form><!--/themify_builder_static-->',
  'post_title' => 'Contact Us',
  'post_excerpt' => '',
  'post_name' => 'contact-us',
  'post_modified' => '2019-09-19 15:22:06',
  'post_modified_gmt' => '2019-09-19 15:22:06',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?page_id=29',
  'menu_order' => 0,
  'post_type' => 'page',
  'meta_input' => 
  array (
    'page_layout' => 'sidebar-none',
    'content_width' => 'full_width',
    'hide_page_title' => 'yes',
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"97a54c2\\",\\"cols\\":[{\\"element_id\\":\\"894b259\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"2103a4e\\",\\"mod_settings\\":{\\"content_text\\":\\"<h1>Contact Us<\\\\/h1>\\",\\"font_color\\":\\"ffffff_1.00\\",\\"text_align\\":\\"center\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}}]}],\\"styling\\":{\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_slider_speed\\":\\"2000\\",\\"background_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/PeopleImages.com-ID1158436.jpg\\",\\"background_repeat\\":\\"fullcover\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color\\":\\"012f5a_0.50\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"background_repeat_inner\\":\\"repeat\\",\\"background_attachment_inner\\":\\"scroll\\",\\"background_position_inner\\":\\"center-center\\",\\"checkbox_padding_inner_apply_all\\":\\"1\\",\\"border_inner-type\\":\\"top\\",\\"top-frame_type\\":\\"top-presets\\",\\"bottom-frame_type\\":\\"bottom-presets\\",\\"left-frame_type\\":\\"left-presets\\",\\"right-frame_type\\":\\"right-presets\\",\\"padding_top\\":\\"13\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"11\\",\\"padding_bottom_unit\\":\\"%\\",\\"border-type\\":\\"top\\",\\"row_width\\":\\"fullwidth\\",\\"animation_effect\\":\\"fadeIn\\"}},{\\"element_id\\":\\"644b759\\",\\"cols\\":[{\\"element_id\\":\\"c9d234c\\",\\"grid_class\\":\\"col4-2\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"ac77ec8\\",\\"mod_settings\\":{\\"content_text\\":\\"<h3>Before Contacting Us<\\\\/h3><p>Lorem ipsum dolor sit amet, consectetur adipisici elit, sed eiusmod tempor incidunt ut labore et dolore magna aliqua. Non equidem inv-<br \\\\/>ideo, miror magis posuere velit aliquet. abore et dolore magna aliqua.<br \\\\/>Non equidem invideo, miror magis posuere velit<\\\\/p>\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"font_color_h3\\":\\"000000_1.00\\",\\"font_size_h3\\":\\"28\\",\\"line_height_h3\\":\\"35\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}}]},{\\"element_id\\":\\"3660d2a\\",\\"grid_class\\":\\"col4-2\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"4cbcd3a\\",\\"mod_settings\\":{\\"content_text\\":\\"<h3>Our Address<\\\\/h3>\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"font_color_h3\\":\\"000000_1.00\\",\\"font_size_h3\\":\\"28\\",\\"line_height_h3\\":\\"35\\",\\"animation_effect\\":\\"fadeInRight\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"mod_name\\":\\"icon\\",\\"element_id\\":\\"cead636\\",\\"mod_settings\\":{\\"icon_size\\":\\"small\\",\\"icon_style\\":\\"none\\",\\"icon_arrangement\\":\\"icon_vertical\\",\\"content_icon\\":[{\\"icon\\":\\"ti-home\\",\\"label\\":\\"233 78th Street, New York, NY 10032\\",\\"link_options\\":\\"regular\\",\\"icon_color_bg\\":\\"tb_default_color\\"},{\\"icon\\":\\"ti-email\\",\\"label\\":\\"youremail@domain.com\\",\\"link_options\\":\\"regular\\",\\"icon_color_bg\\":\\"tb_default_color\\"},{\\"icon\\":\\"fa-phone\\",\\"label\\":\\"1-800-228-3388\\",\\"link_options\\":\\"regular\\",\\"icon_color_bg\\":\\"tb_default_color\\"},{\\"icon\\":\\"ti-time\\",\\"label\\":\\"Everyday 9:00am - 6:00pm\\",\\"link_options\\":\\"regular\\",\\"icon_color_bg\\":\\"tb_default_color\\"}],\\"css_icon\\":\\"contact-info\\",\\"font_size\\":\\"16\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"font_color_icon\\":\\"000000_1.00\\",\\"animation_effect\\":\\"fadeInRight\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"icon_position\\":\\"icon_position_left\\"}}]}],\\"styling\\":{\\"background_color\\":\\"f1f5f9_1.00\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"padding_top\\":\\"4\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"4\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\"}},{\\"element_id\\":\\"986e6ed\\",\\"cols\\":[{\\"element_id\\":\\"5a78ddb\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"element_id\\":\\"3fffb55\\",\\"cols\\":[{\\"element_id\\":\\"a1164b8\\",\\"grid_class\\":\\"col4-1\\",\\"grid_width\\":\\"13.1865\\"},{\\"element_id\\":\\"cb185fb\\",\\"grid_class\\":\\"col4-2\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"70a3581\\",\\"mod_settings\\":{\\"content_text\\":\\"<h2>Contact<\\\\/h2>\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align\\":\\"center\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"margin_bottom\\":\\"10\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"mod_name\\":\\"contact\\",\\"element_id\\":\\"1784cf5\\",\\"mod_settings\\":{\\"layout_contact\\":\\"style1\\",\\"field_subject_active\\":\\"yes\\",\\"field_send_label\\":\\"Send\\",\\"margin_bottom_unit\\":\\"%\\",\\"font_color_labels\\":\\"707070_1.00\\",\\"font_size_labels\\":\\"16\\",\\"text_align_success_message_left\\":\\"left\\",\\"text_align_success_message_center\\":\\"center\\",\\"text_align_success_message_right\\":\\"right\\",\\"text_align_success_message_justify\\":\\"justify\\",\\"text_align_error_message_left\\":\\"left\\",\\"text_align_error_message_center\\":\\"center\\",\\"text_align_error_message_right\\":\\"right\\",\\"text_align_error_message_justify\\":\\"justify\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"field_email_active\\":\\"yes\\",\\"field_name_active\\":\\"yes\\",\\"field_message_active\\":\\"yes\\",\\"field_send_align\\":\\"left\\",\\"field_sendcopy_active\\":\\"\\",\\"field_captcha_active\\":\\"\\",\\"field_subject_require\\":\\"\\",\\"field_email_require\\":\\"\\",\\"field_name_require\\":\\"\\",\\"contact_sent_from\\":\\"enable\\",\\"post_author\\":\\"\\",\\"send_to_admins\\":\\"true\\"}}],\\"grid_width\\":\\"67.2885\\",\\"styling\\":{\\"background_color\\":\\"f8f8f8_1.00\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"padding_top\\":\\"3.75\\",\\"padding_top_unit\\":\\"em\\",\\"padding_right\\":\\"5\\",\\"padding_right_unit\\":\\"%\\",\\"padding_bottom\\":\\"3.75\\",\\"padding_bottom_unit\\":\\"em\\",\\"padding_left\\":\\"5\\",\\"padding_left_unit\\":\\"%\\",\\"custom_css_column\\":\\"contact-form\\"}},{\\"element_id\\":\\"64126c0\\",\\"grid_class\\":\\"col4-1\\",\\"grid_width\\":\\"13.1261\\"}]}]}],\\"styling\\":{\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"padding_top\\":\\"4\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"4\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"animation_effect\\":\\"fadeInUp\\"}}]',
  ),
  'tax_input' => 
  array (
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 5,
  'post_date' => '2016-12-28 04:51:33',
  'post_date_gmt' => '2016-12-28 04:51:33',
  'post_content' => '<!--themify_builder_static--><h1>Lawyer &amp; Attorney</h1><h3>NYC Law Firm and Legal Services</h3>
<a href="#" > Request a Free Consultation </a>
<h2>Our Services</h2>
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/corporate.png" title="Corporate Law" alt="Sed do eiusmod tempor olor sit amet, consectetur a dicing elit, sed zead tempor" /> <h3> Corporate Law </h3> Sed do eiusmod tempor olor sit amet, consectetur a dicing elit, sed zead tempor
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/home-icon.png" title="Real Estate" alt="Quis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil" /> <h3> Real Estate </h3> Quis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/ip-law.png" title="IP Law" alt="Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit" /> <h3> IP Law </h3> Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/immigration.png" title="Immigration" alt="Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu" /> <h3> Immigration </h3> Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/setup-icon.png" title="Business Setup" alt="Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet, consectetur" /> <h3> Business Setup </h3> Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet, consectetur
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/law-icon.png" title="Civil Law" alt="Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt" /> <h3> Civil Law </h3> Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/entertainment.png" title="Entertainment" alt="Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis" /> <h3> Entertainment </h3> Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/will-icon.png" title="Wills " alt="Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque" /> <h3> Wills & Estates </h3> Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque
<h2>Testimonials</h2>
<ul data-id="tb_rp3l49" data-visible="1" data-mob-visible="" data-scroll="1" data-auto-scroll="off" data-speed="1" data-wrap="yes" data-arrow="yes" data-pagination="yes" data-effect="cover-fade" data-height="variable" data-pause-on-hover="resume" data-horizontal="" > <li> 
 <h3>Done it right. Impressive services!</h3> <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Praesent dapibus sodales accumsan. Maecenas vulputate erat, sed varius ipsum velit sed odio.</p> <figure><img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/person-1.png" alt="Done it right. Impressive services!" /></figure> Dennise King CEO Landmark Corporation </li> <li> 
 <h3>A competitively priced and professional service</h3> <p>Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Similique suscipit, id ducimus illum corporis pariatur.</p> <figure><img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/person-2.png" alt="A competitively priced and professional service" /></figure> Robert Gill Vice President Wheel Spain Ltd </li> <li> 
 <h3>We would recommend them thoroughly!</h3> <p>Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae.</p> <figure><img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/person-3.png" alt="We would recommend them thoroughly!" /></figure> Juli Smith Manager County Road Association </li> </ul>
<h2>Our Lawyers</h2>
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/team-1.jpg" title="John Smith" alt="Principle" srcset="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/team-1.jpg 344w, https://themify.me/demo/themes/ultra-lawyer/files/2016/12/team-1-252x300.jpg 252w" sizes="(max-width: 344px) 100vw, 344px" /> <h3> John Smith </h3> Principle
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/team-2.jpg" title="Audi Farrar" alt="Partner" srcset="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/team-2.jpg 250w, https://themify.me/demo/themes/ultra-lawyer/files/2016/12/team-2-183x300.jpg 183w" sizes="(max-width: 250px) 100vw, 250px" /> <h3> Audi Farrar </h3> Partner
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/team-3.jpg" title="Meggie Penn" alt="Manager" srcset="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/team-3.jpg 304w, https://themify.me/demo/themes/ultra-lawyer/files/2016/12/team-3-222x300.jpg 222w" sizes="(max-width: 304px) 100vw, 304px" /> <h3> Meggie Penn </h3> Manager
<h2>FAQs</h2>
<ul><li><h4>What are your fees?</h4><p>Auod blanditiis ullam! Libero rerum, unde iste provident nulla, inventore ratione pariatur speriores vitae, odit dignissimos porrolit. Similique suscipit, id ducimus illum corporis pariatur.</p></li><li><h4>I need to sponsor my family. What is the procedure?</h4><p>ta cum ea volunt retinere, quae superiori sententiae conveniunt, in Aristonem incidunt; In qua si nihil est praeter rationem, sit in una virtute finis bonorum; Nescio quo modo praetervolavit oratio. Non est ista, inquam, Piso, magna dissensio. Sed mehercule pergrata mihi oratio tua. Graccho, eius fere, aequalí? Quasi ego id curem, quid ille aiat aut neget.</p></li><li><h4>Can you help me to close a house?</h4><p>Universa enim illorum ratione cum tota vestra confligendum puto. Ut necesse sit omnium rerum, quae natura vigeant, similem esse finem, non eundem. At enim sequor utilitatem. Ne amores quidem sanctos a sapiente alienos esse arbitrantur.</p></li><li><h4>Got some issues with my property landlord...</h4><p>Quae cum essent dicta, finem fecimus et ambulandi et disputandi. Iam quae corporis sunt, ea nec auctoritatem cum animi partibus, comparandam et cognitionem habent faciliorem. Itaque contra est, ac dicitis; Satisne ergo pudori consulat, si quis sine teste libidini pareat? Causa autem fuit huc veniendi ut quosdam hinc libros promerem. Post enim Chrysippum eum non sane est disputatum.</p></li><li><h4>Do you fight for traffic tickets? </h4><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ita fit cum gravior, tum etiam splendidior oratio. Duo Reges: constructio interrete. Quod ea non occurrentia fingunt, vincunt Aristonem; Non est ista, inquam, Piso, magna dissensio. Indicant pueri, in quibus ut in speculis natura cernitur.</p></li></ul>
<h3>Your question is not here?</h3>
<a href="#" > Email Us </a>
<h2>Press & News</h2>

<h2>Contact</h2>
<form action="https://themify.me/demo/themes/ultra-lawyer/wp-admin/admin-ajax.php" id="contact-0--form" method="post"> <label for="contact-0--contact-name">Name </label> <input type="text" name="contact-name" placeholder="" id="contact-0--contact-name" value="" /> <label for="contact-0--contact-email">Email </label> <input type="text" name="contact-email" placeholder="" id="contact-0--contact-email" value="" /> <label for="contact-0--contact-subject">Subject </label> <input type="text" name="contact-subject" placeholder="" id="contact-0--contact-subject" value="" /> <label for="contact-0--contact-message">Message *</label> <textarea name="contact-message" placeholder="" id="contact-0--contact-message" rows="8" cols="45" required></textarea> <button type="submit"> Send </button> </form><!--/themify_builder_static-->',
  'post_title' => 'Home',
  'post_excerpt' => '',
  'post_name' => 'home',
  'post_modified' => '2019-09-19 15:21:56',
  'post_modified_gmt' => '2019-09-19 15:21:56',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?page_id=5',
  'menu_order' => 0,
  'post_type' => 'page',
  'meta_input' => 
  array (
    'page_layout' => 'sidebar-none',
    'content_width' => 'full_width',
    'hide_page_title' => 'yes',
    'mobile_menu_styles' => 'default',
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"g21937\\",\\"cols\\":[{\\"element_id\\":\\"h96w41\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"qvy142\\",\\"mod_settings\\":{\\"content_text\\":\\"<h1>Lawyer &amp; Attorney<\\\\/h1><h3>NYC Law Firm and Legal Services<\\\\/h3>\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_color_h1\\":\\"ffffff_1.00\\",\\"font_size_h1\\":\\"80\\",\\"font_size_h1_unit\\":\\"px\\",\\"line_height_h1\\":\\"60\\",\\"line_height_h1_unit\\":\\"px\\",\\"font_color_h3\\":\\"ffffff_1.00\\",\\"font_size_h3\\":\\"30\\",\\"font_size_h3_unit\\":\\"px\\",\\"line_height_h3\\":\\"70\\",\\"line_height_h3_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_mobile\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_h1\\":\\"60\\",\\"font_size_h1_unit\\":\\"px\\",\\"font_size_h3\\":\\"23\\",\\"font_size_h3_unit\\":\\"px\\"}}},{\\"mod_name\\":\\"buttons\\",\\"element_id\\":\\"hzvn43\\",\\"mod_settings\\":{\\"buttons_size\\":\\"xlarge\\",\\"buttons_style\\":\\"squared\\",\\"content_button\\":[{\\"label\\":\\"Request a Free Consultation\\",\\"link\\":\\"#\\",\\"link_options\\":\\"regular\\",\\"button_color_bg\\":\\"blue\\"}],\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"font_size\\":\\"16\\",\\"font_size_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"padding_top_link\\":\\"18\\",\\"padding_top_link_unit\\":\\"px\\",\\"padding_right_link\\":\\"30\\",\\"padding_right_link_unit\\":\\"px\\",\\"padding_bottom_link\\":\\"18\\",\\"padding_bottom_link_unit\\":\\"px\\",\\"padding_left_link\\":\\"30\\",\\"padding_left_link_unit\\":\\"px\\",\\"checkbox_link_padding_apply_all\\":\\"padding\\",\\"checkbox_link_padding_apply_all_padding\\":\\"padding\\",\\"link_checkbox_margin_apply_all\\":\\"margin\\",\\"link_checkbox_margin_apply_all_margin\\":\\"margin\\",\\"link_checkbox_border_apply_all\\":\\"border\\",\\"link_checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_mobile\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"padding_top_link\\":\\"18\\",\\"padding_top_link_unit\\":\\"px\\",\\"padding_right_link\\":\\"12\\",\\"padding_right_link_unit\\":\\"px\\",\\"padding_bottom_link\\":\\"18\\",\\"padding_bottom_link_unit\\":\\"px\\",\\"padding_left_link\\":\\"12\\",\\"padding_left_link_unit\\":\\"px\\",\\"checkbox_link_padding_apply_all_padding\\":\\"padding\\",\\"link_checkbox_margin_apply_all\\":\\"margin\\",\\"link_checkbox_margin_apply_all_margin\\":\\"margin\\",\\"link_checkbox_border_apply_all\\":\\"border\\",\\"link_checkbox_border_apply_all_border\\":\\"border\\"}}}]}],\\"styling\\":{\\"row_width\\":\\"fullwidth\\",\\"background_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/hero-banner-1024x490.jpg\\",\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_repeat\\":\\"fullcover\\",\\"background_position\\":\\"center-top\\",\\"cover_color-type\\":\\"color\\",\\"cover_color\\":\\"242424_0.60\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"text_align\\":\\"center\\",\\"padding_top\\":\\"16\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"12\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"animation_effect\\":\\"fadeIn\\"}},{\\"element_id\\":\\"mqfg37\\",\\"cols\\":[{\\"element_id\\":\\"j2d644\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"goz844\\",\\"mod_settings\\":{\\"content_text\\":\\"<h2>Our Services<\\\\/h2>\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"30\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"element_id\\":\\"b75344\\",\\"cols\\":[{\\"element_id\\":\\"p1xb45\\",\\"grid_class\\":\\"col4-1\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"pkrp45\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/corporate.png\\",\\"title_image\\":\\"Corporate Law\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Sed do eiusmod tempor olor sit amet, consectetur a dicing elit, sed zead tempor \\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"20\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}},{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"d27s46\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/home-icon.png\\",\\"title_image\\":\\"Real Estate\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Quis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top\\":\\"20\\",\\"margin_top_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInRight\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}}],\\"styling\\":{\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color-type\\":\\"color\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"checkbox_padding_apply_all\\":\\"padding\\"}},{\\"element_id\\":\\"bvj046\\",\\"grid_class\\":\\"col4-1\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"c2wa46\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/ip-law.png\\",\\"title_image\\":\\"IP Law\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit \\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"20\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}},{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"7iwl47\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/immigration.png\\",\\"title_image\\":\\"Immigration\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top\\":\\"20\\",\\"margin_top_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInRight\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}}]},{\\"element_id\\":\\"5aje47\\",\\"grid_class\\":\\"col4-1\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"yrea47\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/setup-icon.png\\",\\"title_image\\":\\"Business Setup\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet, consectetur\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"20\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}},{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"t8xb47\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/law-icon.png\\",\\"title_image\\":\\"Civil Law\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top\\":\\"20\\",\\"margin_top_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInRight\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}}]},{\\"element_id\\":\\"zu4k47\\",\\"grid_class\\":\\"col4-1\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"6c2d48\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/entertainment.png\\",\\"title_image\\":\\"Entertainment\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"20\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}},{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"dp5a48\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/will-icon.png\\",\\"title_image\\":\\"Wills & Estates\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top\\":\\"20\\",\\"margin_top_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInRight\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}}]}]}]}],\\"styling\\":{\\"custom_css_row\\":\\"our-services\\",\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color-type\\":\\"color\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"padding_top\\":\\"4\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"4\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\"}},{\\"element_id\\":\\"3jwf37\\",\\"cols\\":[{\\"element_id\\":\\"qzub48\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"rqj648\\",\\"mod_settings\\":{\\"content_text\\":\\"<h2>Testimonials<\\\\/h2>\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"30\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"mod_name\\":\\"testimonial-slider\\",\\"element_id\\":\\"rp3l49\\",\\"mod_settings\\":{\\"layout_testimonial\\":\\"image-bottom\\",\\"tab_content_testimonial\\":[{\\"title_testimonial\\":\\"Done it right. Impressive services!\\",\\"content_testimonial\\":\\"<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Praesent dapibus sodales accumsan. Maecenas vulputate erat, sed varius ipsum velit sed odio.<\\\\/p>\\",\\"person_picture_testimonial\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/person-1.png\\",\\"person_name_testimonial\\":\\"Dennise King\\",\\"person_position_testimonial\\":\\"CEO\\",\\"company_testimonial\\":\\"Landmark Corporation\\"},{\\"title_testimonial\\":\\"A competitively priced and professional service\\",\\"content_testimonial\\":\\"<p>Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Similique suscipit, id ducimus illum corporis pariatur.<\\\\/p>\\",\\"person_picture_testimonial\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/person-2.png\\",\\"person_name_testimonial\\":\\"Robert Gill\\",\\"person_position_testimonial\\":\\"Vice President\\",\\"company_testimonial\\":\\"Wheel Spain Ltd\\"},{\\"title_testimonial\\":\\"We would recommend them thoroughly!\\",\\"content_testimonial\\":\\"<p>Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae.<\\\\/p>\\",\\"person_picture_testimonial\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/person-3.png\\",\\"person_name_testimonial\\":\\"Juli Smith\\",\\"person_position_testimonial\\":\\"Manager\\",\\"company_testimonial\\":\\"County Road Association\\"}],\\"visible_opt_slider\\":\\"1\\",\\"auto_scroll_opt_slider\\":\\"off\\",\\"scroll_opt_slider\\":\\"1\\",\\"speed_opt_slider\\":\\"normal\\",\\"effect_slider\\":\\"cover-fade\\",\\"pause_on_hover_slider\\":\\"resume\\",\\"wrap_slider\\":\\"yes\\",\\"show_nav_slider\\":\\"yes\\",\\"show_arrow_slider\\":\\"yes\\",\\"show_arrow_buttons_vertical\\":\\"vertical\\",\\"height_slider\\":\\"variable\\",\\"css_testimonial\\":\\"testimonials\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}}]}],\\"styling\\":{\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_color\\":\\"f1f5f9_1.00\\",\\"cover_color-type\\":\\"color\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"padding_top\\":\\"4\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"4\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\"}},{\\"element_id\\":\\"48uo37\\",\\"cols\\":[{\\"element_id\\":\\"wkqh49\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"swya49\\",\\"mod_settings\\":{\\"content_text\\":\\"<h2>Our Lawyers<\\\\/h2>\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"30\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"element_id\\":\\"lpeo49\\",\\"cols\\":[{\\"element_id\\":\\"828x50\\",\\"grid_class\\":\\"col3-1\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"spcn50\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/team-1.jpg\\",\\"title_image\\":\\"John Smith\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Principle\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}}]},{\\"element_id\\":\\"04t950\\",\\"grid_class\\":\\"col3-1\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"vwq550\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/team-2.jpg\\",\\"title_image\\":\\"Audi Farrar\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Partner\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}}]},{\\"element_id\\":\\"tule50\\",\\"grid_class\\":\\"col3-1\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"vkxc50\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/team-3.jpg\\",\\"title_image\\":\\"Meggie Penn\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Manager\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}}]}],\\"column_alignment\\":\\"col_align_bottom\\"}]}],\\"styling\\":{\\"custom_css_row\\":\\"lawyers-section\\",\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color-type\\":\\"color\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"padding_top\\":\\"4\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"4\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\"}},{\\"element_id\\":\\"tz5z38\\",\\"cols\\":[{\\"element_id\\":\\"72pl51\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"iay951\\",\\"mod_settings\\":{\\"content_text\\":\\"<h2>FAQs<\\\\/h2>\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"font_color\\":\\"ffffff_1.00\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"30\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"mod_name\\":\\"accordion\\",\\"element_id\\":\\"1gvz51\\",\\"mod_settings\\":{\\"expand_collapse_accordion\\":\\"toggle\\",\\"color_accordion\\":\\"transparent\\",\\"icon_accordion\\":\\"ti-plus\\",\\"icon_active_accordion\\":\\"ti-minus\\",\\"content_accordion\\":[{\\"title_accordion\\":\\"What are your fees?\\",\\"text_accordion\\":\\"<p>Auod blanditiis ullam! Libero rerum, unde iste provident nulla, inventore ratione pariatur speriores vitae, odit dignissimos porrolit. Similique suscipit, id ducimus illum corporis pariatur.<\\\\/p>\\",\\"default_accordion\\":\\"open\\"},{\\"title_accordion\\":\\"I need to sponsor my family. What is the procedure?\\",\\"text_accordion\\":\\"<p>ta cum ea volunt retinere, quae superiori sententiae conveniunt, in Aristonem incidunt; In qua si nihil est praeter rationem, sit in una virtute finis bonorum; Nescio quo modo praetervolavit oratio. Non est ista, inquam, Piso, magna dissensio. Sed mehercule pergrata mihi oratio tua. Graccho, eius fere, aequalí? Quasi ego id curem, quid ille aiat aut neget.<\\\\/p>\\"},{\\"title_accordion\\":\\"Can you help me to close a house?\\",\\"text_accordion\\":\\"<p>Universa enim illorum ratione cum tota vestra confligendum puto. Ut necesse sit omnium rerum, quae natura vigeant, similem esse finem, non eundem. At enim sequor utilitatem. Ne amores quidem sanctos a sapiente alienos esse arbitrantur.<\\\\/p>\\"},{\\"title_accordion\\":\\"Got some issues with my property landlord...\\",\\"text_accordion\\":\\"<p>Quae cum essent dicta, finem fecimus et ambulandi et disputandi. Iam quae corporis sunt, ea nec auctoritatem cum animi partibus, comparandam et cognitionem habent faciliorem. Itaque contra est, ac dicitis; Satisne ergo pudori consulat, si quis sine teste libidini pareat? Causa autem fuit huc veniendi ut quosdam hinc libros promerem. Post enim Chrysippum eum non sane est disputatum.<\\\\/p>\\"},{\\"title_accordion\\":\\"Do you fight for traffic tickets?  \\",\\"text_accordion\\":\\"<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ita fit cum gravior, tum etiam splendidior oratio. Duo Reges: constructio interrete. Quod ea non occurrentia fingunt, vincunt Aristonem; Non est ista, inquam, Piso, magna dissensio. Indicant pueri, in quibus ut in speculis natura cernitur.<\\\\/p>\\"}],\\"font_color\\":\\"ffffff_1.00\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"font_size_title\\":\\"30\\",\\"font_size_title_unit\\":\\"px\\",\\"font_size_content\\":\\"16\\",\\"font_size_content_unit\\":\\"px\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInUpBig\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"mxl351\\",\\"mod_settings\\":{\\"content_text\\":\\"<h3>Your question is not here?<\\\\/h3>\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top\\":\\"50\\",\\"margin_top_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_color_h3\\":\\"ffffff_1.00\\",\\"line_height_h3\\":\\"50\\",\\"line_height_h3_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInRight\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"mod_name\\":\\"buttons\\",\\"element_id\\":\\"xfng52\\",\\"mod_settings\\":{\\"buttons_size\\":\\"xlarge\\",\\"buttons_style\\":\\"squared\\",\\"content_button\\":[{\\"label\\":\\"Email Us\\",\\"link\\":\\"#\\",\\"link_options\\":\\"regular\\",\\"button_color_bg\\":\\"blue\\"}],\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"font_color\\":\\"ffffff_1.00\\",\\"font_size\\":\\"20\\",\\"font_size_unit\\":\\"px\\",\\"line_height\\":\\"20\\",\\"line_height_unit\\":\\"px\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"checkbox_link_padding_apply_all\\":\\"padding\\",\\"checkbox_link_padding_apply_all_padding\\":\\"padding\\",\\"link_checkbox_margin_apply_all\\":\\"margin\\",\\"link_checkbox_margin_apply_all_margin\\":\\"margin\\",\\"link_checkbox_border_apply_all\\":\\"border\\",\\"link_checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}}]}],\\"styling\\":{\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_color\\":\\"35405d_1.00\\",\\"cover_color-type\\":\\"color\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"padding_top\\":\\"5\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"5\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\"}},{\\"element_id\\":\\"jshn38\\",\\"cols\\":[{\\"element_id\\":\\"cndp52\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"3fdw52\\",\\"mod_settings\\":{\\"content_text\\":\\"<h2>Press & News<\\\\/h2>\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"30\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"mod_name\\":\\"post\\",\\"element_id\\":\\"70t052\\",\\"mod_settings\\":{\\"layout_post\\":\\"grid3\\",\\"post_type_post\\":\\"post\\",\\"type_query_post\\":\\"category\\",\\"category_post\\":\\"0|multiple\\",\\"post_tag_post\\":\\"0|multiple\\",\\"post_per_page_post\\":\\"3\\",\\"order_post\\":\\"desc\\",\\"orderby_post\\":\\"date\\",\\"display_post\\":\\"excerpt\\",\\"img_width_post\\":\\"360\\",\\"img_height_post\\":\\"244\\",\\"hide_post_meta_post\\":\\"yes\\",\\"hide_page_nav_post\\":\\"yes\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}}]}],\\"styling\\":{\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color-type\\":\\"color\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"padding_top\\":\\"5\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"5\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\"}},{\\"element_id\\":\\"eh8w38\\",\\"cols\\":[{\\"element_id\\":\\"rn8j53\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"element_id\\":\\"zh3653\\",\\"cols\\":[{\\"element_id\\":\\"xzu253\\",\\"grid_class\\":\\"col4-1\\",\\"grid_width\\":\\"13.1865\\"},{\\"element_id\\":\\"k1j953\\",\\"grid_class\\":\\"col4-2\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"j5pe54\\",\\"mod_settings\\":{\\"content_text\\":\\"<h2>Contact<\\\\/h2>\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"mod_name\\":\\"contact\\",\\"element_id\\":\\"wz9554\\",\\"mod_settings\\":{\\"layout_contact\\":\\"style1\\",\\"field_subject_active\\":\\"yes\\",\\"field_send_label\\":\\"Send\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"margin_bottom_unit\\":\\"%\\",\\"font_color_labels\\":\\"707070_1.00\\",\\"font_size_labels\\":\\"16\\",\\"font_size_labels_unit\\":\\"px\\",\\"text_align_success_message_left\\":\\"left\\",\\"text_align_success_message_center\\":\\"center\\",\\"text_align_success_message_right\\":\\"right\\",\\"text_align_success_message_justify\\":\\"justify\\",\\"text_align_error_message_left\\":\\"left\\",\\"text_align_error_message_center\\":\\"center\\",\\"text_align_error_message_right\\":\\"right\\",\\"text_align_error_message_justify\\":\\"justify\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"field_email_active\\":\\"yes\\",\\"field_name_active\\":\\"yes\\",\\"field_message_active\\":\\"yes\\",\\"field_send_align\\":\\"left\\",\\"contact_sent_from\\":\\"enable\\",\\"send_to_admins\\":\\"true\\",\\"field_sendcopy_active\\":\\"\\",\\"field_captcha_active\\":\\"\\",\\"field_subject_require\\":\\"\\",\\"field_email_require\\":\\"\\",\\"field_name_require\\":\\"\\",\\"post_author\\":\\"\\"}}],\\"grid_width\\":\\"67.2885\\",\\"styling\\":{\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_color\\":\\"f8f8f8_1.00\\",\\"cover_color-type\\":\\"color\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"padding_top\\":\\"3.75\\",\\"padding_top_unit\\":\\"em\\",\\"padding_right\\":\\"5\\",\\"padding_right_unit\\":\\"%\\",\\"padding_bottom\\":\\"3.75\\",\\"padding_bottom_unit\\":\\"em\\",\\"padding_left\\":\\"5\\",\\"padding_left_unit\\":\\"%\\",\\"custom_css_column\\":\\"contact-form\\"}},{\\"element_id\\":\\"jlqx54\\",\\"grid_class\\":\\"col4-1\\",\\"grid_width\\":\\"13.1261\\"}],\\"col_mobile\\":\\"column-full\\"}]}],\\"styling\\":{\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color-type\\":\\"color\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"padding_top\\":\\"0\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"4\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\"}}]',
  ),
  'tax_input' => 
  array (
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 134,
  'post_date' => '2016-12-29 12:56:56',
  'post_date_gmt' => '2016-12-29 12:56:56',
  'post_content' => '',
  'post_title' => 'News',
  'post_excerpt' => '',
  'post_name' => 'news',
  'post_modified' => '2016-12-29 21:56:25',
  'post_modified_gmt' => '2016-12-29 21:56:25',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?page_id=134',
  'menu_order' => 0,
  'post_type' => 'page',
  'meta_input' => 
  array (
    'hide_page_title' => 'yes',
    'footer_design' => 'footer-left-col',
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":{\\"1\\":{\\"mod_name\\":\\"post\\",\\"mod_settings\\":{\\"layout_post\\":\\"list-post\\",\\"post_type_post\\":\\"post\\",\\"type_query_post\\":\\"category\\",\\"category_post\\":\\"0|multiple\\",\\"post_tag_post\\":\\"0|multiple\\",\\"post_per_page_post\\":\\"5\\",\\"order_post\\":\\"desc\\",\\"orderby_post\\":\\"date\\",\\"display_post\\":\\"excerpt\\",\\"img_width_post\\":\\"820\\",\\"img_height_post\\":\\"440\\",\\"hide_post_meta_post\\":\\"yes\\",\\"hide_page_nav_post\\":\\"no\\",\\"font_family\\":\\"default\\",\\"font_size_unit\\":\\"px\\",\\"line_height_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"border_top_style\\":\\"solid\\",\\"border_right_style\\":\\"solid\\",\\"border_bottom_style\\":\\"solid\\",\\"border_left_style\\":\\"solid\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_title\\":\\"default\\",\\"font_size_title_unit\\":\\"px\\",\\"line_height_title_unit\\":\\"px\\",\\"font_family_meta\\":\\"default\\",\\"font_size_meta_unit\\":\\"px\\",\\"line_height_meta_unit\\":\\"px\\",\\"font_family_date\\":\\"default\\",\\"font_size_date_unit\\":\\"px\\",\\"line_height_date_unit\\":\\"px\\",\\"font_family_content\\":\\"default\\",\\"font_size_content_unit\\":\\"px\\",\\"line_height_content_unit\\":\\"px\\",\\"custom_parallax_scroll_reverse\\":\\"|\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade\\":\\"|\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop\\":\\"show\\",\\"visibility_desktop_show\\":\\"show\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet\\":\\"show\\",\\"visibility_tablet_show\\":\\"show\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile\\":\\"show\\",\\"visibility_mobile_show\\":\\"show\\",\\"visibility_mobile_hide\\":\\"hide\\"}}},\\"styling\\":[]}],\\"styling\\":[]},{\\"row_order\\":\\"1\\",\\"gutter\\":\\"gutter-default\\",\\"equal_column_height\\":\\"\\",\\"column_alignment\\":\\"col_align_top\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"grid_width\\":\\"\\",\\"modules\\":[],\\"styling\\":[]}],\\"styling\\":[]}]',
  ),
  'tax_input' => 
  array (
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 27,
  'post_date' => '2016-12-28 05:25:03',
  'post_date_gmt' => '2016-12-28 05:25:03',
  'post_content' => '<!--themify_builder_static--><h1>Services</h1>
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/service-1.jpg" alt="service 1" srcset="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/service-1.jpg 527w, https://themify.me/demo/themes/ultra-lawyer/files/2016/12/service-1-300x251.jpg 300w" sizes="(max-width: 527px) 100vw, 527px" />
<h5>That clients\' success determines our own. So we ensure both by clients\' success determines our collaborating.</h5><p>eleifend ut nibh at, tempus vehicula sapien. Phasellus eu pharetra leo. Mauris feugiat, tortor vel congue pretium, est urna vulputate mi, et tincidunt ipsum leo non nulla. Aenean et felis elit.</p>
<a href="#" > DISCUSS YOUR CASE </a>
<h2>Our Services</h2>
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/corporate-1.png" title="Corporate Law" alt="Sed do eiusmod tempor olor sit amet, consectetur a dicing elit, sed zead tempor" /> <h3> Corporate Law </h3> Sed do eiusmod tempor olor sit amet, consectetur a dicing elit, sed zead tempor
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/home-icon.png" title="Real Estate" alt="Quis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil" /> <h3> Real Estate </h3> Quis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/ip-law.png" title="IP Law" alt="Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit" /> <h3> IP Law </h3> Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/immigration.png" title="Immigration" alt="Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu" /> <h3> Immigration </h3> Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/setup-icon.png" title="Business Setup" alt="Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet, consectetur" /> <h3> Business Setup </h3> Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet, consectetur
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/law-icon.png" title="Civil Law" alt="Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt" /> <h3> Civil Law </h3> Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/entertainment.png" title="Entertainment" alt="Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis" /> <h3> Entertainment </h3> Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/will-icon.png" title="Wills " alt="Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque" /> <h3> Wills & Estates </h3> Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque
<h1>CALL US</h1><h2><em>1-800-228-3388</em></h2><p>-OR-</p>
<a href="#" > Request a Free Consultation </a>
<h2>Our Case Process</h2>
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/icon-1.png" title="Detailed Planning" alt="Tuod blanditiis ullam! Libero rerum, unde iste , inventore ratione pariatur" /> <h3> Detailed Planning </h3> Tuod blanditiis ullam! Libero rerum, unde iste , inventore ratione pariatur
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/icon-2.png" title="Settlement" alt="Then buod blanditiis ullam! Libero rerum, unde iste, pariaturthe phantom." /> <h3> Settlement </h3> Then buod blanditiis ullam! Libero rerum, unde iste, pariaturthe phantom.
<img src="https://themify.me/demo/themes/ultra-lawyer/files/2016/12/icon-3.png" title="Fight for Justice" alt="A blanditiis ullam! Libero rerum, unde iste , inventore ratione pariatur." /> <h3> Fight for Justice </h3> A blanditiis ullam! Libero rerum, unde iste , inventore ratione pariatur.<!--/themify_builder_static-->',
  'post_title' => 'Services',
  'post_excerpt' => '',
  'post_name' => 'services',
  'post_modified' => '2019-05-07 19:00:00',
  'post_modified_gmt' => '2019-05-07 19:00:00',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?page_id=27',
  'menu_order' => 0,
  'post_type' => 'page',
  'meta_input' => 
  array (
    'page_layout' => 'sidebar-none',
    'content_width' => 'full_width',
    'hide_page_title' => 'yes',
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"llsu823\\",\\"cols\\":[{\\"element_id\\":\\"vkem830\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"7g05833\\",\\"mod_settings\\":{\\"content_text\\":\\"<h1>Services<\\\\/h1>\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"font_color\\":\\"ffffff_1.00\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}}]}],\\"styling\\":{\\"row_width\\":\\"fullwidth\\",\\"background_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/service-bg-1024x319.jpg\\",\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_repeat\\":\\"fullcover\\",\\"background_position\\":\\"center-top\\",\\"cover_color-type\\":\\"color\\",\\"cover_color\\":\\"012f5a_0.50\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"padding_top\\":\\"13\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"11\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"animation_effect\\":\\"fadeIn\\"}},{\\"element_id\\":\\"0bpq824\\",\\"cols\\":[{\\"element_id\\":\\"mp12840\\",\\"grid_class\\":\\"col4-2\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"y406840\\",\\"mod_settings\\":{\\"style_image\\":\\"image-overlay\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/service-1.jpg\\",\\"param_image\\":\\"regular\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_right\\":\\"-90\\",\\"margin_right_unit\\":\\"%\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}}],\\"grid_width\\":\\"44.3678\\"},{\\"element_id\\":\\"kkix841\\",\\"grid_class\\":\\"col4-2\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"dtan841\\",\\"mod_settings\\":{\\"content_text\\":\\"<h5>That clients\\\\\\\\\\\' success determines our own. So we ensure both by clients\\\\\\\\\\\' success determines our collaborating.<\\\\/h5><p>eleifend ut nibh at, tempus vehicula sapien. Phasellus eu pharetra leo. Mauris feugiat, tortor vel congue pretium, est urna vulputate mi, et tincidunt ipsum leo non nulla. Aenean et felis elit.<\\\\/p>\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"em\\",\\"padding_right_unit\\":\\"em\\",\\"padding_bottom_unit\\":\\"em\\",\\"padding_left_unit\\":\\"em\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_h3\\":\\"24\\",\\"font_size_h3_unit\\":\\"px\\",\\"line_height_h3\\":\\"30\\",\\"line_height_h3_unit\\":\\"px\\",\\"animation_effect\\":\\"fadeInRight\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet_landscape\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"em\\",\\"padding_right_unit\\":\\"em\\",\\"padding_bottom_unit\\":\\"em\\",\\"padding_left_unit\\":\\"em\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\"},\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"em\\",\\"padding_right_unit\\":\\"em\\",\\"padding_bottom_unit\\":\\"em\\",\\"padding_left_unit\\":\\"em\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\"},\\"breakpoint_mobile\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\"}}},{\\"mod_name\\":\\"buttons\\",\\"element_id\\":\\"6bs6841\\",\\"mod_settings\\":{\\"buttons_size\\":\\"large\\",\\"content_button\\":[{\\"label\\":\\"DISCUSS YOUR CASE\\",\\"link\\":\\"#\\",\\"link_options\\":\\"regular\\",\\"button_color_bg\\":\\"blue\\",\\"icon_alignment\\":\\"left\\"}],\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"font_size\\":\\"16\\",\\"font_size_unit\\":\\"px\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"button_background_color\\":\\"057adc_1.00\\",\\"button_hover_background_color\\":\\"186bb1_1.00\\",\\"checkbox_link_padding_apply_all\\":\\"padding\\",\\"checkbox_link_padding_apply_all_padding\\":\\"padding\\",\\"link_checkbox_margin_apply_all\\":\\"margin\\",\\"link_checkbox_margin_apply_all_margin\\":\\"margin\\",\\"link_checkbox_border_apply_all\\":\\"border\\",\\"link_checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInRight\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"display\\":\\"buttons-horizontal\\",\\"buttons_shape\\":\\"squared\\"}}],\\"grid_width\\":\\"52.4325\\",\\"styling\\":{\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_color\\":\\"ffffff_1.00\\",\\"cover_color-type\\":\\"color\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"padding_top\\":\\"4.3\\",\\"padding_top_unit\\":\\"em\\",\\"padding_right\\":\\"7\\",\\"padding_right_unit\\":\\"em\\",\\"padding_bottom\\":\\"4.3\\",\\"padding_bottom_unit\\":\\"em\\",\\"padding_left\\":\\"4.3\\",\\"padding_left_unit\\":\\"em\\",\\"breakpoint_tablet_landscape\\":{\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color-type\\":\\"color\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"padding_top\\":\\"4.3\\",\\"padding_top_unit\\":\\"em\\",\\"padding_right\\":\\"4\\",\\"padding_right_unit\\":\\"em\\",\\"padding_bottom\\":\\"4.3\\",\\"padding_bottom_unit\\":\\"em\\",\\"padding_left\\":\\"3\\",\\"padding_left_unit\\":\\"em\\"},\\"breakpoint_tablet\\":{\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color-type\\":\\"color\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"padding_top\\":\\"4.3\\",\\"padding_top_unit\\":\\"em\\",\\"padding_right\\":\\"3\\",\\"padding_right_unit\\":\\"em\\",\\"padding_bottom\\":\\"4.3\\",\\"padding_bottom_unit\\":\\"em\\",\\"padding_left\\":\\"2\\",\\"padding_left_unit\\":\\"em\\"},\\"breakpoint_mobile\\":{\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color-type\\":\\"color\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"padding_top\\":\\"2.3\\",\\"padding_top_unit\\":\\"em\\",\\"padding_right\\":\\"1.5\\",\\"padding_right_unit\\":\\"em\\",\\"padding_bottom\\":\\"2.3\\",\\"padding_bottom_unit\\":\\"em\\",\\"padding_left\\":\\"1.5\\",\\"padding_left_unit\\":\\"em\\"}}}],\\"styling\\":{\\"custom_css_row\\":\\"service-section\\",\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_color\\":\\"f5f4f4_1.00\\",\\"cover_color-type\\":\\"color\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"padding_top\\":\\"7\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"10\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\"}},{\\"element_id\\":\\"1d0p824\\",\\"cols\\":[{\\"element_id\\":\\"7hhq842\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"4e0g842\\",\\"mod_settings\\":{\\"content_text\\":\\"<h2>Our Services<\\\\/h2>\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"30\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"element_id\\":\\"ky5a843\\",\\"cols\\":[{\\"element_id\\":\\"20li844\\",\\"grid_class\\":\\"col4-1\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"r9fs844\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/corporate-1.png\\",\\"title_image\\":\\"Corporate Law\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Sed do eiusmod tempor olor sit amet, consectetur a dicing elit, sed zead tempor \\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"20\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInRight\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}},{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"i9z7844\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/home-icon.png\\",\\"title_image\\":\\"Real Estate\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Quis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top\\":\\"20\\",\\"margin_top_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}}]},{\\"element_id\\":\\"phvi845\\",\\"grid_class\\":\\"col4-1\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"axjy845\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/ip-law.png\\",\\"title_image\\":\\"IP Law\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit \\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"20\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInRight\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}},{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"hytt845\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/immigration.png\\",\\"title_image\\":\\"Immigration\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu \\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top\\":\\"20\\",\\"margin_top_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}}]},{\\"element_id\\":\\"msfv845\\",\\"grid_class\\":\\"col4-1\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"sue8845\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/setup-icon.png\\",\\"title_image\\":\\"Business Setup\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet, consectetur \\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"20\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInRight\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}},{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"n8nc845\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/law-icon.png\\",\\"title_image\\":\\"Civil Law\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top\\":\\"20\\",\\"margin_top_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}}]},{\\"element_id\\":\\"ljlf846\\",\\"grid_class\\":\\"col4-1\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"ggms846\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/entertainment.png\\",\\"title_image\\":\\"Entertainment\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"20\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInRight\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}},{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"idny846\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/will-icon.png\\",\\"title_image\\":\\"Wills & Estates\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_top\\":\\"20\\",\\"margin_top_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}}]}]}]}],\\"styling\\":{\\"custom_css_row\\":\\"our-services\\",\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color-type\\":\\"color\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"padding_top\\":\\"4\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"4\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\"}},{\\"element_id\\":\\"31kn824\\",\\"cols\\":[{\\"element_id\\":\\"tzqy846\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"element_id\\":\\"uq3d847\\",\\"cols\\":[{\\"element_id\\":\\"ot80847\\",\\"grid_class\\":\\"col4-2\\"},{\\"element_id\\":\\"mwob847\\",\\"grid_class\\":\\"col4-2\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"delg847\\",\\"mod_settings\\":{\\"content_text\\":\\"<h1>CALL US<\\\\/h1><h2><em>1-800-228-3388<\\\\/em><\\\\/h2><p>-OR-<\\\\/p>\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"font_color\\":\\"ffffff_1.00\\",\\"font_size\\":\\"18\\",\\"font_size_unit\\":\\"px\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_family_h1\\":\\"Open Sans\\",\\"font_size_h1\\":\\"63\\",\\"font_size_h1_unit\\":\\"px\\",\\"line_height_h1\\":\\"60\\",\\"line_height_h1_unit\\":\\"px\\",\\"font_family_h2\\":\\"Judson\\",\\"font_size_h2\\":\\"72\\",\\"font_size_h2_unit\\":\\"px\\",\\"line_height_h2\\":\\"55\\",\\"line_height_h2_unit\\":\\"px\\",\\"breakpoint_tablet_landscape\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_h1\\":\\"50\\",\\"font_size_h1_unit\\":\\"px\\",\\"font_size_h2\\":\\"53\\",\\"font_size_h2_unit\\":\\"px\\"},\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_h1\\":\\"45\\",\\"font_size_h1_unit\\":\\"px\\",\\"line_height_h1\\":\\"50\\",\\"line_height_h1_unit\\":\\"px\\",\\"font_size_h2\\":\\"44\\",\\"font_size_h2_unit\\":\\"px\\",\\"line_height_h2\\":\\"52\\",\\"line_height_h2_unit\\":\\"px\\"},\\"breakpoint_mobile\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_h1\\":\\"50\\",\\"font_size_h1_unit\\":\\"px\\",\\"line_height_h1\\":\\"60\\",\\"line_height_h1_unit\\":\\"px\\",\\"font_size_h2\\":\\"44\\",\\"font_size_h2_unit\\":\\"px\\"}}},{\\"mod_name\\":\\"buttons\\",\\"element_id\\":\\"umgo847\\",\\"mod_settings\\":{\\"buttons_size\\":\\"xlarge\\",\\"buttons_style\\":\\"squared\\",\\"content_button\\":[{\\"label\\":\\"Request a Free Consultation\\",\\"link\\":\\"#\\",\\"link_options\\":\\"regular\\",\\"button_color_bg\\":\\"blue\\"}],\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"font_size\\":\\"16\\",\\"font_size_unit\\":\\"px\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"padding_top_link\\":\\"18\\",\\"padding_top_link_unit\\":\\"px\\",\\"padding_right_link\\":\\"30\\",\\"padding_right_link_unit\\":\\"px\\",\\"padding_bottom_link\\":\\"18\\",\\"padding_bottom_link_unit\\":\\"px\\",\\"padding_left_link\\":\\"30\\",\\"padding_left_link_unit\\":\\"px\\",\\"checkbox_link_padding_apply_all_padding\\":\\"padding\\",\\"link_checkbox_margin_apply_all\\":\\"margin\\",\\"link_checkbox_margin_apply_all_margin\\":\\"margin\\",\\"link_checkbox_border_apply_all\\":\\"border\\",\\"link_checkbox_border_apply_all_border\\":\\"border\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"padding_top_link\\":\\"18\\",\\"padding_top_link_unit\\":\\"px\\",\\"padding_right_link\\":\\"4\\",\\"padding_right_link_unit\\":\\"px\\",\\"padding_bottom_link\\":\\"18\\",\\"padding_bottom_link_unit\\":\\"px\\",\\"padding_left_link\\":\\"4\\",\\"padding_left_link_unit\\":\\"px\\",\\"checkbox_link_padding_apply_all_padding\\":\\"padding\\",\\"link_checkbox_margin_apply_all\\":\\"margin\\",\\"link_checkbox_margin_apply_all_margin\\":\\"margin\\",\\"link_checkbox_border_apply_all\\":\\"border\\",\\"link_checkbox_border_apply_all_border\\":\\"border\\"},\\"breakpoint_mobile\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"padding_top_link\\":\\"18\\",\\"padding_top_link_unit\\":\\"px\\",\\"padding_right_link\\":\\"4\\",\\"padding_right_link_unit\\":\\"px\\",\\"padding_bottom_link\\":\\"18\\",\\"padding_bottom_link_unit\\":\\"px\\",\\"padding_left_link\\":\\"4\\",\\"padding_left_link_unit\\":\\"px\\",\\"checkbox_link_padding_apply_all_padding\\":\\"padding\\",\\"link_checkbox_margin_apply_all\\":\\"margin\\",\\"link_checkbox_margin_apply_all_margin\\":\\"margin\\",\\"link_checkbox_border_apply_all\\":\\"border\\",\\"link_checkbox_border_apply_all_border\\":\\"border\\"}}}]}]}]}],\\"styling\\":{\\"background_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/call-us-1024x380.jpg\\",\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_repeat\\":\\"fullcover\\",\\"background_position\\":\\"center-top\\",\\"cover_color-type\\":\\"color\\",\\"cover_color\\":\\"012f5a_0.50\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"padding_top\\":\\"9\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"8\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"animation_effect\\":\\"zoomIn\\"}},{\\"element_id\\":\\"gz0x824\\",\\"cols\\":[{\\"element_id\\":\\"pu28848\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"v0is849\\",\\"mod_settings\\":{\\"content_text\\":\\"<h2>Our Case Process<\\\\/h2>\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"background_image-css\\":\\"background-image: -moz-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -webkit-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -o-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: -ms-linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\nbackground-image: linear-gradient(180deg,rgb(0, 0, 0) 0%, rgb(255, 255, 255) 100%);\\\\n\\",\\"text_align\\":\\"center\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"30\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInUp\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}},{\\"element_id\\":\\"a0ko849\\",\\"cols\\":[{\\"element_id\\":\\"n6x4849\\",\\"grid_class\\":\\"col3-1\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"66jp850\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/icon-1.png\\",\\"appearance_image\\":\\"circle\\",\\"title_image\\":\\"Detailed Planning\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Tuod blanditiis ullam! Libero rerum, \\\\nunde iste , inventore ratione pariatur\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"20\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}}]},{\\"element_id\\":\\"j24e850\\",\\"grid_class\\":\\"col3-1\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"fy0e850\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/icon-2.png\\",\\"title_image\\":\\"Settlement\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"Then buod blanditiis ullam! Libero rerum, \\\\nunde iste, pariaturthe phantom.\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"20\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}}]},{\\"element_id\\":\\"h4gf850\\",\\"grid_class\\":\\"col3-1\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"l233850\\",\\"mod_settings\\":{\\"style_image\\":\\"image-center\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-lawyer\\\\/files\\\\/2016\\\\/12\\\\/icon-3.png\\",\\"title_image\\":\\"Fight for Justice\\",\\"param_image\\":\\"regular\\",\\"caption_image\\":\\"A blanditiis ullam! Libero rerum, \\\\nunde iste , inventore ratione pariatur.\\",\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"margin_bottom\\":\\"20\\",\\"margin_bottom_unit\\":\\"px\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\",\\"breakpoint_tablet\\":{\\"background_image-type_gradient\\":\\"gradient\\",\\"background_image-gradient-angle\\":\\"0\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_padding_apply_all_padding\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_margin_apply_all_margin\\":\\"margin\\",\\"checkbox_border_apply_all_border\\":\\"border\\",\\"font_size_title\\":\\"22\\",\\"font_size_title_unit\\":\\"px\\"}}}]}]}]}],\\"styling\\":{\\"custom_css_row\\":\\"our-services\\",\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color-type\\":\\"color\\",\\"cover_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"cover_gradient_hover-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"padding_top\\":\\"4\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"7\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\"}}]',
  ),
  'tax_input' => 
  array (
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 34,
  'post_date' => '2016-12-28 05:27:00',
  'post_date_gmt' => '2016-12-28 05:27:00',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '34',
  'post_modified' => '2016-12-29 13:04:19',
  'post_modified_gmt' => '2016-12-29 13:04:19',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=34',
  'menu_order' => 1,
  'post_type' => 'nav_menu_item',
  'xfn' => '',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '5',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-navigation',
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 33,
  'post_date' => '2016-12-28 05:27:00',
  'post_date_gmt' => '2016-12-28 05:27:00',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '33',
  'post_modified' => '2016-12-29 13:04:19',
  'post_modified_gmt' => '2016-12-29 13:04:19',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=33',
  'menu_order' => 2,
  'post_type' => 'nav_menu_item',
  'xfn' => '',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '25',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-navigation',
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 32,
  'post_date' => '2016-12-28 05:27:00',
  'post_date_gmt' => '2016-12-28 05:27:00',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '32',
  'post_modified' => '2016-12-29 13:04:19',
  'post_modified_gmt' => '2016-12-29 13:04:19',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=32',
  'menu_order' => 3,
  'post_type' => 'nav_menu_item',
  'xfn' => '',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '27',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-navigation',
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 139,
  'post_date' => '2016-12-29 13:04:19',
  'post_date_gmt' => '2016-12-29 13:04:19',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '139',
  'post_modified' => '2016-12-29 13:04:19',
  'post_modified_gmt' => '2016-12-29 13:04:19',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=139',
  'menu_order' => 4,
  'post_type' => 'nav_menu_item',
  'xfn' => '',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '134',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-navigation',
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 31,
  'post_date' => '2016-12-28 05:27:00',
  'post_date_gmt' => '2016-12-28 05:27:00',
  'post_content' => '',
  'post_title' => 'Contact',
  'post_excerpt' => '',
  'post_name' => '31',
  'post_modified' => '2016-12-29 13:04:19',
  'post_modified_gmt' => '2016-12-29 13:04:19',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-lawyer/?p=31',
  'menu_order' => 5,
  'post_type' => 'nav_menu_item',
  'xfn' => '',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '29',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-navigation',
  ),
);
themify_process_post_import( $post );



function themify_import_get_term_id_from_slug( $slug ) {
	$menu = get_term_by( "slug", $slug, "nav_menu" );
	return is_wp_error( $menu ) ? 0 : (int) $menu->term_id;
}

	$widgets = get_option( "widget_themify-feature-posts" );
$widgets[1002] = array (
  'title' => 'Recent Posts',
  'category' => '0',
  'show_count' => '5',
  'show_date' => NULL,
  'show_thumb' => 'on',
  'display' => 'none',
  'hide_title' => NULL,
  'thumb_width' => '50',
  'thumb_height' => '50',
  'excerpt_length' => '55',
  'orderby' => 'date',
  'order' => 'DESC',
);
update_option( "widget_themify-feature-posts", $widgets );

$widgets = get_option( "widget_themify-twitter" );
$widgets[1003] = array (
  'title' => 'Latest Tweets',
  'username' => '@themify',
  'show_count' => '5',
  'hide_timestamp' => NULL,
  'show_follow' => NULL,
  'follow_text' => '→ Follow me',
  'include_retweets' => NULL,
  'exclude_replies' => NULL,
);
update_option( "widget_themify-twitter", $widgets );

$widgets = get_option( "widget_themify-social-links" );
$widgets[1004] = array (
  'title' => '',
  'show_link_name' => NULL,
  'open_new_window' => 'on',
  'icon_size' => 'icon-medium',
  'orientation' => 'horizontal',
);
update_option( "widget_themify-social-links", $widgets );

$widgets = get_option( "widget_text" );
$widgets[1005] = array (
  'title' => '',
  'text' => 'CALL US:  <a href="tel:1-800-228-3388">1-800-228-3388</a>',
  'filter' => false,
);
update_option( "widget_text", $widgets );

$widgets = get_option( "widget_text" );
$widgets[1006] = array (
  'title' => '',
  'text' => '<a href="#">Request a Free Consultation</a>',
  'filter' => false,
);
update_option( "widget_text", $widgets );

$widgets = get_option( "widget_themify-feature-posts" );
$widgets[1007] = array (
  'title' => 'News',
  'category' => '0',
  'show_count' => '3',
  'show_date' => NULL,
  'show_thumb' => 'on',
  'display' => 'none',
  'hide_title' => NULL,
  'thumb_width' => '50',
  'thumb_height' => '50',
  'excerpt_length' => '55',
  'orderby' => 'date',
  'order' => 'DESC',
);
update_option( "widget_themify-feature-posts", $widgets );

$widgets = get_option( "widget_text" );
$widgets[1008] = array (
  'title' => 'Location',
  'text' => '233 78th Street<br/>
New York, <br/>
NY 10032<br/><br/>

1-800-228-3388',
  'filter' => false,
);
update_option( "widget_text", $widgets );

$widgets = get_option( "widget_text" );
$widgets[1009] = array (
  'title' => '',
  'text' => '[themify_map address="35 Adelaide Street East Toronto, Ontario" width="100%" zoom="4" type="roadmap" ]',
  'filter' => true,
  'visual' => true,
);
update_option( "widget_text", $widgets );



$sidebars_widgets = array (
  'sidebar-main' => 
  array (
    0 => 'themify-feature-posts-1002',
    1 => 'themify-twitter-1003',
  ),
  'footer-social-widget' => 
  array (
    0 => 'themify-social-links-1004',
  ),
  'header-widget-1' => 
  array (
    0 => 'text-1005',
  ),
  'header-widget-2' => 
  array (
    0 => 'text-1006',
  ),
  'footer-widget-1' => 
  array (
    0 => 'themify-feature-posts-1007',
  ),
  'footer-widget-2' => 
  array (
    0 => 'text-1008',
  ),
  'footer-widget-3' => 
  array (
    0 => 'text-1009',
  ),
); 
update_option( "sidebars_widgets", $sidebars_widgets );

$menu_locations = array();
$menu = get_terms( "nav_menu", array( "slug" => "main-navigation" ) );
if( is_array( $menu ) && ! empty( $menu ) ) $menu_locations["main-nav"] = $menu[0]->term_id;
set_theme_mod( "nav_menu_locations", $menu_locations );


$homepage = get_posts( array( 'name' => 'home', 'post_type' => 'page' ) );
			if( is_array( $homepage ) && ! empty( $homepage ) ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $homepage[0]->ID );
			}
			
	ob_start(); ?>a:115:{s:21:"setting-webfonts_list";s:11:"recommended";s:22:"setting-default_layout";s:8:"sidebar1";s:27:"setting-default_post_layout";s:9:"list-post";s:19:"setting-post_filter";s:3:"yes";s:23:"setting-disable_masonry";s:3:"yes";s:19:"setting-post_gutter";s:6:"gutter";s:30:"setting-default_layout_display";s:7:"excerpt";s:25:"setting-default_more_text";s:4:"More";s:21:"setting-index_orderby";s:4:"date";s:19:"setting-index_order";s:4:"DESC";s:35:"setting-default_display_date_inline";s:1:"1";s:30:"setting-default_media_position";s:5:"above";s:31:"setting-image_post_feature_size";s:5:"blank";s:24:"setting-image_post_width";s:3:"826";s:25:"setting-image_post_height";s:3:"470";s:32:"setting-default_page_post_layout";s:8:"sidebar1";s:37:"setting-default_page_post_layout_type";s:7:"classic";s:40:"setting-default_page_display_date_inline";s:1:"1";s:42:"setting-default_page_single_media_position";s:5:"above";s:38:"setting-image_post_single_feature_size";s:5:"blank";s:31:"setting-image_post_single_width";s:3:"826";s:32:"setting-image_post_single_height";s:3:"470";s:36:"setting-search-result_layout_display";s:7:"content";s:36:"setting-search-result_media_position";s:5:"above";s:27:"setting-default_page_layout";s:8:"sidebar1";s:40:"setting-custom_post_tglobal_style_single";s:8:"sidebar1";s:38:"setting-default_portfolio_index_layout";s:12:"sidebar-none";s:43:"setting-default_portfolio_index_post_layout";s:5:"grid3";s:29:"setting-portfolio_post_filter";s:3:"yes";s:33:"setting-portfolio_disable_masonry";s:3:"yes";s:24:"setting-portfolio_gutter";s:6:"gutter";s:39:"setting-default_portfolio_index_display";s:4:"none";s:50:"setting-default_portfolio_index_post_meta_category";s:3:"yes";s:49:"setting-default_portfolio_index_unlink_post_image";s:3:"yes";s:39:"setting-default_portfolio_single_layout";s:12:"sidebar-none";s:54:"setting-default_portfolio_single_portfolio_layout_type";s:9:"fullwidth";s:50:"setting-default_portfolio_single_unlink_post_image";s:3:"yes";s:22:"themify_portfolio_slug";s:7:"project";s:31:"themify_portfolio_category_slug";s:18:"portfolio-category";s:53:"setting-customizer_responsive_design_tablet_landscape";s:4:"1024";s:43:"setting-customizer_responsive_design_tablet";s:3:"768";s:43:"setting-customizer_responsive_design_mobile";s:3:"480";s:33:"setting-mobile_menu_trigger_point";s:4:"1200";s:24:"setting-gallery_lightbox";s:8:"lightbox";s:11:"setting-css";s:3:"top";s:27:"setting-page_builder_expiry";s:1:"2";s:21:"setting-header_design";s:18:"header-top-widgets";s:27:"setting-exclude_search_form";s:2:"on";s:19:"setting_search_form";s:11:"live_search";s:19:"setting-exclude_rss";s:2:"on";s:29:"setting-exclude_social_widget";s:2:"on";s:22:"setting-header_widgets";s:17:"headerwidget-2col";s:21:"setting-footer_design";s:15:"footer-left-col";s:22:"setting-footer_widgets";s:17:"footerwidget-3col";s:23:"setting-mega_menu_posts";s:1:"5";s:29:"setting-mega_menu_image_width";s:3:"180";s:30:"setting-mega_menu_image_height";s:3:"120";s:27:"setting-imagefilter_applyto";s:12:"featuredonly";s:29:"setting-color_animation_speed";s:1:"5";s:29:"setting-relationship_taxonomy";s:8:"category";s:37:"setting-relationship_taxonomy_entries";s:1:"3";s:45:"setting-relationship_taxonomy_display_content";s:4:"none";s:30:"setting-single_slider_autoplay";s:3:"off";s:27:"setting-single_slider_speed";s:6:"normal";s:28:"setting-single_slider_effect";s:5:"slide";s:28:"setting-single_slider_height";s:4:"auto";s:18:"setting-more_posts";s:8:"infinite";s:19:"setting-entries_nav";s:8:"numbered";s:24:"setting-footer_text_left";s:50:"Copyright © 2016 The Company, All Rights Reserved";s:30:"setting-footer_text_right_hide";s:4:"hide";s:25:"setting-img_php_base_size";s:5:"large";s:27:"setting-global_feature_size";s:5:"blank";s:22:"setting-link_icon_type";s:9:"font-icon";s:32:"setting-link_type_themify-link-0";s:10:"image-icon";s:33:"setting-link_title_themify-link-0";s:7:"Twitter";s:31:"setting-link_img_themify-link-0";s:106:"https://themify.me/demo/themes/ultra-lawyer/wp-content/themes/themify-ultra/themify/img/social/twitter.png";s:32:"setting-link_type_themify-link-1";s:10:"image-icon";s:33:"setting-link_title_themify-link-1";s:8:"Facebook";s:31:"setting-link_img_themify-link-1";s:107:"https://themify.me/demo/themes/ultra-lawyer/wp-content/themes/themify-ultra/themify/img/social/facebook.png";s:32:"setting-link_type_themify-link-2";s:10:"image-icon";s:33:"setting-link_title_themify-link-2";s:7:"Google+";s:31:"setting-link_img_themify-link-2";s:110:"https://themify.me/demo/themes/ultra-lawyer/wp-content/themes/themify-ultra/themify/img/social/google-plus.png";s:32:"setting-link_type_themify-link-3";s:10:"image-icon";s:33:"setting-link_title_themify-link-3";s:7:"YouTube";s:31:"setting-link_img_themify-link-3";s:106:"https://themify.me/demo/themes/ultra-lawyer/wp-content/themes/themify-ultra/themify/img/social/youtube.png";s:32:"setting-link_type_themify-link-4";s:10:"image-icon";s:33:"setting-link_title_themify-link-4";s:9:"Pinterest";s:31:"setting-link_img_themify-link-4";s:108:"https://themify.me/demo/themes/ultra-lawyer/wp-content/themes/themify-ultra/themify/img/social/pinterest.png";s:32:"setting-link_type_themify-link-6";s:9:"font-icon";s:33:"setting-link_title_themify-link-6";s:8:"Facebook";s:32:"setting-link_link_themify-link-6";s:28:"https://facebook.com/themify";s:33:"setting-link_ficon_themify-link-6";s:11:"fa-facebook";s:32:"setting-link_type_themify-link-5";s:9:"font-icon";s:33:"setting-link_title_themify-link-5";s:7:"Twitter";s:32:"setting-link_link_themify-link-5";s:27:"https://twitter.com/themify";s:33:"setting-link_ficon_themify-link-5";s:10:"fa-twitter";s:32:"setting-link_type_themify-link-7";s:9:"font-icon";s:33:"setting-link_title_themify-link-7";s:7:"Google+";s:32:"setting-link_link_themify-link-7";s:1:"#";s:33:"setting-link_ficon_themify-link-7";s:14:"fa-google-plus";s:32:"setting-link_type_themify-link-8";s:9:"font-icon";s:33:"setting-link_title_themify-link-8";s:7:"YouTube";s:33:"setting-link_ficon_themify-link-8";s:10:"fa-youtube";s:32:"setting-link_type_themify-link-9";s:9:"font-icon";s:33:"setting-link_title_themify-link-9";s:9:"Pinterest";s:33:"setting-link_ficon_themify-link-9";s:12:"fa-pinterest";s:22:"setting-link_field_ids";s:341:"{"themify-link-0":"themify-link-0","themify-link-1":"themify-link-1","themify-link-2":"themify-link-2","themify-link-3":"themify-link-3","themify-link-4":"themify-link-4","themify-link-6":"themify-link-6","themify-link-5":"themify-link-5","themify-link-7":"themify-link-7","themify-link-8":"themify-link-8","themify-link-9":"themify-link-9"}";s:23:"setting-link_field_hash";s:2:"10";s:30:"setting-page_builder_is_active";s:6:"enable";s:41:"setting-page_builder_animation_appearance";s:4:"none";s:42:"setting-page_builder_animation_parallax_bg";s:4:"none";s:44:"setting-page_builder_animation_scroll_effect";s:4:"none";s:44:"setting-page_builder_animation_sticky_scroll";s:4:"none";s:4:"skin";s:6:"lawyer";s:13:"import_images";s:2:"on";}<?php $themify_data = unserialize( ob_get_clean() );

	// Fix how "skin" option used to save
	if( isset( $themify_data['skin'] ) ) {
		if ( filter_var( $themify_data['skin'], FILTER_VALIDATE_URL ) ) {
			$parsed_skin = parse_url( $value, PHP_URL_PATH );
			$themify_data['skin'] = basename( dirname( $parsed_skin ) );
		}
	}

	themify_set_data( $themify_data );
	
}
themify_do_demo_import();