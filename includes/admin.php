<?php

class TeolazCptPageRelationAdminInterface
{

    private $post_types;

    private $languages;

    private $post_page_rel;

    private $pages;

    public function __construct()
    {
        add_action('init', array(
            $this,
            'initPlugin'
        ),10000);
        add_action('admin_menu', array(
            $this,
            'addAdminMenu'
        ));
        add_action('admin_enqueue_scripts', array(
            $this,
            'setScripts'
        ));
    }

    public function addAdminMenu()
    {
        add_options_page(__('Teolaz CPT-Page Relation', TEXTDOMAIN), __('Teolaz CPT-Page Relation', TEXTDOMAIN), 'manage_options', 'teolaz-cpt-page-relation-menu', array(
            $this,
            'setMenuPage'
        ));
    }

    function setScripts()
    {
        wp_enqueue_style('teolaz-cpt-page-relation', plugin_dir_url(__FILE__) . '../assets/style.css');
    }

    function initPlugin()
    {
        $this->post_types = get_post_types(array(
            'publicly_queryable' => true,
            '_builtin' => false
        ), 'objects');
        $this->languages = null;
        if (function_exists('icl_get_languages')) {
            $this->languages = icl_get_languages('skip_missing=0&orderby=name');
            $pages = get_pages(array(
                'sort_column' => 'menu_order'
            ));
            $this->pages = array();
            foreach ($this->languages as $language) :
                $language_code = $language['language_code'];
                $this->pages[$language_code] = array();
                foreach ($pages as $page) :
                    $temp_page = icl_object_id($page->ID, 'page', false, $language_code);
                    $temp_page = get_post($temp_page);
                    if ($temp_page)
                        $this->pages[$language_code][] = $temp_page;
                endforeach
                ;
            endforeach
            ;
        } else {
            $this->pages = get_pages(array(
                'sort_column' => 'menu_order'
            ));
        }
        $this->post_page_rel = get_option('teolaz-cpt-page-relation', null);
    }

    function saveValues()
    {
        update_option('teolaz-cpt-page-relation', $_POST['resultPosts']);
        $this->post_page_rel = get_option('teolaz-cpt-page-relation', null);
    }

    public function setMenuPage()
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        if (isset($_POST['hidden_field']) && $_POST['hidden_field'] == 'Y') {
            $this->saveValues();
        }
        // add thickbox support
        add_thickbox();
        ?>
<h1><?php _e('Teolaz CPT-Page Relation Options',TEXTDOMAIN);?></h1>
<hr>
<form id="form" name="form" method="post">
	<h2><?php _e('Post Types',TEXTDOMAIN);?></h2>

	<div class="teolaz-table">
		<table>
			<thead>
				<tr>
					<th><?php _e('Name',TEXTDOMAIN); ?></th>
					<th><?php _e('Modify relation for each language',TEXTDOMAIN);?></th>
				</tr>
			</thead>
			<tbody>
                <?php
        foreach ($this->post_types as $post_type) :
            ?>
                <tr>
					<td><?php echo $post_type->labels->singular_name; ?></td>
					<td><?php
            if ($this->languages) {
                // we have WPML installed
                foreach ($this->languages as $language) :
				?>
                    <div class="flag">
                        <img class="flag-selector" src="<?php echo $language['country_flag_url'];?>" /> : 
                        <select name="<?php echo 'resultPosts['.$post_type->name.']['.$language['language_code'].']';?>">
                                <?php
                                $pages = $this->pages[$language['language_code']];
                                echo '<option value=""> --- </option>';
                                foreach ($pages as $page) :
                                    $selected = ($this->post_page_rel[$post_type->name][$language['language_code']] == $page->ID) ? ' selected = "selected"' : '';
                                    echo '<option value="' . $page->ID . '"' . $selected . '>' . $page->post_title . '</option>';
                                endforeach
                                ;
                                ?>
                                </select>
                    </div>
                    <?php
                endforeach
                ;
            } else {
                ?>
                        <div class="flag">
                            <?php _e('Default Language',TEXTDOMAIN); ?>
                            <select name="<?php echo 'resultPosts['.$post_type->name.'][default]';?>">
                            <?php
                            $pages = $this->pages;
                            echo '<option value=""> --- </option>';
                            foreach ($pages as $page) :
                                $selected = ($this->post_page_rel[$post_type->name]['default'] == $page->ID) ? ' selected = "selected"' : '';
                                echo '<option value="' . $page->ID . '"' . $selected . '>' . $page->post_title . '</option>';
                            endforeach
                            ;
                            ?>
                            </select>					
                        </div>
                        <?php
            }
            
            ?>   
                </td>
            </tr>
            <?php
        endforeach
        ;
        ?>
            </tbody>
		</table>
	</div>
	<div class="teolaz-button">
		<input type="submit" value="<?php _e('Save',TEXTDOMAIN); ?>" /> <input
			name="hidden_field" type="hidden" value="Y" />
	</div>
</form>
<?php
    }
}
?>