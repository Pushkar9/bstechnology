    <?php
if (!defined('ABSPATH'))
    exit;

/**
 * Template Fancy Heading
 * 
 * Access original fields: $args['mod_settings']
 * @author Themify
 */
if (TFCache::start_cache($args['mod_name'], self::$post_id, array('ID' => $args['module_ID']))):
    $fields_default = array(
        'heading' => '',
        'heading_tag' => 'h1',
	'heading_link' => '',
	'sub_heading_link' => '',
        'sub_heading' => '',
        'text_alignment' => 'tb-align-center',
        'animation_effect' => '',
        'css_class' => '',
        'divider' => 'yes'
    );

    $fields_args = wp_parse_args($args['mod_settings'], $fields_default);
    unset($args['mod_settings']);
    $mod_name=$args['mod_name'];
    $builder_id = $args['builder_id'];
    $element_id = isset($args['element_id'])?'tb_'.$args['element_id']:$args['module_ID'];
    $container_class = apply_filters('themify_builder_module_classes', array(
        'module',
	'module-' . $mod_name,
	$element_id,
	self::parse_animation_effect($fields_args['animation_effect'], $fields_args), 
	$fields_args['css_class']
    ), $mod_name, $element_id, $fields_args);
   
    if(!empty($fields_args['global_styles']) && Themify_Builder::$frontedit_active===false){
	$container_class[] = $fields_args['global_styles'];
    }
    $container_props = apply_filters('themify_builder_module_container_props', array(
        'class' => implode(' ', $container_class),
            ), $fields_args, $mod_name, $element_id);
    
    $args=null;
    $mainTag = '' !== $fields_args['heading_link']?'a':'span';
    $subTag =  '' !== $fields_args['sub_heading_link']?'a':'span';
    if($fields_args['text_alignment']==='themify-text-right'){
	$alignment = 'tb-align-right';
    }
    elseif($fields_args['text_alignment']==='themify-text-left'){
	$alignment = 'tb-align-left';
    }
    elseif($fields_args['text_alignment']==='themify-text-justify'){
	$alignment = 'tb-align-justify';
    }
    elseif($fields_args['text_alignment']==='themify-text-center'){
	$alignment = 'tb-align-center';
    }
    else{
	$alignment='';
    }
    ?>
    <!-- module fancy heading -->
    <div <?php echo self::get_element_attributes(self::sticky_element_props($container_props,$fields_args)); ?>>
	<?php $container_props=$container_class=null;
	do_action('themify_builder_background_styling',$builder_id,array('styling'=>$fields_args,'mod_name'=>$mod_name),$element_id,'module');
	?>
        <<?php echo $fields_args['heading_tag']; ?> class="fancy-heading <?php echo $alignment; ?>">
        <span  class="main-head"<?php if(Themify_Builder::$frontedit_active===true):?> contenteditable="false" data-name="heading" <?php endif; ?>>
            <?php if('' !== $fields_args['heading_link']){ ?>
            <a href="<?php echo $fields_args['heading_link']?>"><?php echo $fields_args['heading']; ?></a>
            <?php }else{ echo $fields_args['heading']; } ?>
        </span>
        <span class="sub-head<?php echo 'no' === $fields_args['divider'] ? ' tb_hide_divider' : ''; ?>"<?php if(Themify_Builder::$frontedit_active===true):?> contenteditable="false" data-name="sub_heading"<?php endif; ?>>
            <?php if('' !== $fields_args['sub_heading_link']){ ?>
                <a href="<?php echo $fields_args['sub_heading_link']?>"><?php echo $fields_args['sub_heading']; ?></a>
            <?php }else{ echo $fields_args['sub_heading']; } ?>
        </span>

        </<?php echo $fields_args['heading_tag']; ?>>
    </div>
    <!-- /module fancy heading -->
<?php endif; ?>
<?php TFCache::end_cache(); ?>
