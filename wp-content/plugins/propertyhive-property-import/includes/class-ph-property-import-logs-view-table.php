<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( !class_exists('WP_List_Table') )
{
   require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

/**
 * Logs View Import Table Functions
 */
class PH_Property_Import_Logs_View_Table extends WP_List_Table {

	public function __construct( $args = array() ) 
    {
        parent::__construct( array(
            'singular'=> 'Log Entry',
            'plural' => 'Log Entries',
            'ajax'   => false // We won't support Ajax for this table, ye
        ) );
	}

    public function extra_tablenav( $which ) 
    {
        /*if ( $which == "top" )
        {
            //The code that goes before the table is here
            echo"Hello, I'm before the table";
        }
        if ( $which == "bottom" )
        {
            //The code that goes after the table is there
            echo"Hi, I'm after the table";
        }*/
    }

    public function get_columns() 
    {
        $columns = array(
            'col_log_date' => __('Date / Time', 'propertyhive' ),
            'col_log_property' => __( 'Related To Property', 'propertyhive' ),
            'col_log_crm_id' => __( 'CRM ID', 'propertyhive' ),
            'col_log_entry' => __( 'Log Entry', 'propertyhive' ),
        );

        if ( isset($_GET['log_id']) && !empty((int)$_GET['log_id']) )
        {
            global $wpdb;

            $import_id = false;

            if ( isset($_GET['import_id']) && !empty((int)$_GET['import_id']) )
            {
                $import_id = (int)$_GET['import_id'];
            }
            else
            {
                // Need to check if format is rtdf to know whether to show received data column
                $row = $wpdb->get_row( "
                    SELECT 
                        import_id
                    FROM 
                        " .$wpdb->prefix . "ph_propertyimport_instance_v3
                    WHERE
                        id = '" . (int)$_GET['log_id'] . "'
                ", ARRAY_A);
                if ( null !== $row )
                {
                    $import_id = (int)$row['import_id'];  
                }
            }

            if ( !empty($import_id) )
            {
                $import_options = get_option( 'propertyhive_property_import' );
                if ( is_array($import_options) && !empty($import_options) )
                {
                    if ( 
                        isset($import_options[$import_id]) && 
                        isset($import_options[$import_id]['format']) && 
                        $import_options[$import_id]['format'] == 'rtdf'
                    )
                    {
                        $columns['col_received_data'] = __('Received Data', 'propertyhive' );
                    }
                }
            }
        }

        return $columns;
    }

    public function column_default( $item, $column_name )
    {
        switch( $column_name ) 
        {
            case 'col_log_date':
            {
                $return = get_date_from_gmt( $item->log_date, "H:i:s jS F Y" );

                return $return;
            }
            case 'col_log_property':
            {
                if ( empty($item->post_id) )
                {
                    return '-';
                }

                $title = get_the_title($item->post_id);
                if ( empty($title) )
                {
                    $title = '(no title)';
                }

                return '<a href="' . get_edit_post_link($item->post_id) . '" target="_blank">' . $title . '</a>';
            }
            case 'col_log_crm_id':
            {
                return $item->crm_id;
            }
            case 'col_log_entry':
            {   
                $return = $item->entry;
                if ( strpos($item->entry, '<iframe') )
                {
                    $return = htmlentities($item->entry);
                }

                return $return;
            }
            case 'col_received_data':
            {   
                $return = htmlentities($item->received_data);

                return $return;
            }
            default:
                return print_r( $item, true ) ;
        }
    }

    public function prepare_items() 
    {
        global $wpdb;

        $columns = $this->get_columns(); 
        $hidden = array();
        $sortable = $this->get_sortable_columns();

        $per_page = 100000;
        $current_page = $this->get_pagenum();
        $offset = ( $current_page - 1 ) * $per_page;

        $this->_column_headers = array($columns, $hidden, $sortable);

        $query = $wpdb->prepare("SELECT
            log_date,
            entry,
            post_id,
            crm_id,
            received_data
        FROM 
            " . $wpdb->prefix . "ph_propertyimport_instance_log_v3
        WHERE
            instance_id = %d
        ORDER BY id ASC", (int)$_GET['log_id']);

        $this->items = $wpdb->get_results($query);
        $totalitems = count($this->items);

        $this->set_pagination_args(
            array(
                'total_items' => $totalitems,
                'per_page'    => $per_page,
            )
        );
        
    }

    public function display() {
        $singular = $this->_args['singular'];

        $this->screen->render_screen_reader_content( 'heading_list' );
        ?>
<table class="wp-list-table <?php echo implode( ' ', $this->get_table_classes() ); ?>">
    <thead>
    <tr>
        <?php $this->print_column_headers(); ?>
    </tr>
    </thead>

    <tbody id="the-list"
        <?php
        if ( $singular ) {
            echo ' data-wp-lists="list:' . esc_attr($singular) . '"';
        }
        ?>
        >
        <?php $this->display_rows_or_placeholder(); ?>
    </tbody>

</table>
        <?php
    }

    protected function get_table_classes() {
        $mode = get_user_setting( 'posts_list_mode', 'list' );

        $mode_class = esc_attr( 'table-view-' . $mode );

        return array( 'widefat', 'striped', $mode_class, esc_attr($this->_args['plural']) );
    }

}