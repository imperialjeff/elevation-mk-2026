<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( !class_exists('WP_List_Table') )
{
   require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

/**
 * Logs Import Table Functions
 */
class PH_Property_Import_Logs_Table extends WP_List_Table {

	public function __construct( $args = array() ) 
    {
        parent::__construct( array(
            'singular'=> 'Log',
            'plural' => 'Logs',
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
        return array(
            'col_log_date'=>__('Date / Time', 'propertyhive' ),
            'col_log_duration'=>__( 'Duration', 'propertyhive' ),
            'col_log_import_format'=>__( 'Import Format', 'propertyhive' ),
        );
    }

    public function column_default( $item, $column_name )
    {
        switch( $column_name ) 
        {
            case 'col_log_date':
            {
                $return = '<strong><a href="' . admin_url('admin.php?page=propertyhive_import_properties&tab=logs&action=view&log_id=' . $item->id . ( ( isset($_GET['import_id']) && !empty((int)$_GET['import_id']) ) ? '&import_id=' . (int)$_GET['import_id'] : '' ) . '&paged=' . ( isset($_GET['paged']) ? (int)$_GET['paged'] : '' ) . '&orderby=' . ( isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : '' ) . '&order=' . ( isset($_GET['order']) ? sanitize_text_field($_GET['order']) : '' ) ) . '">' . get_date_from_gmt( $item->start_date, "H:i:s jS F Y" ) . '</a></strong>';

                $return .= '<div class="row-actions">
                        <span class="edit"><a href="' . admin_url('admin.php?page=propertyhive_import_properties&tab=logs&action=view&log_id=' . $item->id . ( ( isset($_GET['import_id']) && !empty((int)$_GET['import_id']) ) ? '&import_id=' . (int)$_GET['import_id'] : '' ) . '&paged=' . ( isset($_GET['paged']) ? (int)$_GET['paged'] : '' ) . '&orderby=' . ( isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : '' ) . '&order=' . ( isset($_GET['order']) ? sanitize_text_field($_GET['order']) : '' ) ) . '" aria-label="' . __( 'View Log', 'propertyhive' ) . '">' . __( 'View Log', 'propertyhive' ) . '</a></span>
                    </div>';

                return $return;
            }
            case 'col_log_duration':
            {
                if ( $item->end_date == '0000-00-00 00:00:00' )
                {
                    if ($item->media == '1')
                    {
                        return 'Running now: Importing media';
                    }

                    if ( $item->status_date != '0000-00-00 00:00:00' )
                    {
                        if ( ( ( time() - strtotime($item->status_date) ) / 60 ) < 5 )
                        {
                            if ( !empty($item->status) )
                            {
                                $status = json_decode($item->status, true);
                                if ( isset($status['status']) )
                                {
                                    if ( $status['status'] == 'importing' )
                                    {
                                        return 'Running now: Importing property ' . $status['property'] . '/' . $status['total'];
                                    }
                                    return 'Running now: ' . ucfirst($status['status']);
                                }
                                
                            }
                        }
                        else
                        {
                            return 'Failed to complete';
                        }
                    }
                    return '-';
                }

                $diff = '';

                $diff_secs = strtotime($item->end_date) - strtotime($item->start_date);

                if ( $diff_secs >= 60 )
                {
                    $diff_mins = floor( $diff_secs / 60 );
                    $diff = $diff_mins . ' minutes, ';
                    $diff_secs = $diff_secs - ( $diff_mins * 60 );
                }

                $diff .= $diff_secs . ' seconds';

                return $diff;
            }
            case 'col_log_import_format':
            {
                $format = propertyhive_property_import_get_format_from_import_id( $item->import_id );

                if ( $format === false)
                {
                    return '-';
                }

                return $format['name'];
            }
            default:
                return print_r( $item, true ) ;
        }
    }

    // Adding sortable columns
    public function get_sortable_columns() 
    {
        $sortable_columns = array(
            'col_log_date' => array('start_date', 'asc')
        );
        return $sortable_columns;
    }

    public function prepare_items() 
    {
        global $wpdb;

        $columns = $this->get_columns(); 
        $hidden = array();
        $sortable = $this->get_sortable_columns();

        $per_page = apply_filters('propertyhive_property_import_logs_per_page', 50);
        $current_page = $this->get_pagenum();
        $offset = ( $current_page - 1 ) * $per_page;

        $this->_column_headers = array($columns, $hidden, $sortable);

        $query = "SELECT
            COUNT(*)
        FROM 
            " . $wpdb->prefix . "ph_propertyimport_instance_v3 ";
        if ( isset($_GET['import_id']) && !empty((int)$_GET['import_id']) )
        {
            $query .= " WHERE import_id = '" . (int)$_GET['import_id'] . "' ";
        }

        $totalitems = $wpdb->get_var($query);

        $orderby = (!empty($_GET['orderby'])) ? sanitize_text_field($_GET['orderby']) : 'start_date'; // default order
        $order = (!empty($_GET['order'])) ? sanitize_text_field($_GET['order']) : 'asc'; // default order direction

        $query = "SELECT
            id, 
            start_date, 
            end_date, 
            status, 
            status_date, 
            import_id,
            media
        FROM 
            " . $wpdb->prefix . "ph_propertyimport_instance_v3 ";
        if ( isset($_GET['import_id']) && !empty((int)$_GET['import_id']) )
        {
            $query .= " WHERE import_id = '" . (int)$_GET['import_id'] . "' ";
        }
        $query .= " ORDER BY $orderby $order";
        $query .= $wpdb->prepare(" LIMIT %d OFFSET %d", $per_page, ($current_page - 1) * $per_page);

        $this->items = $wpdb->get_results($query);

        $this->set_pagination_args(
            array(
                'total_items' => $totalitems,
                'per_page'    => $per_page,
                'total_pages' => ceil($totalitems / $per_page),
            )
        );
        
    }

    public function display() {
        $singular = $this->_args['singular'];

        // Add pagination above the table
        $this->display_tablenav( 'top' );

        $this->screen->render_screen_reader_content( 'heading_list' );
        ?>
<table class="wp-list-table <?php echo esc_attr(implode( ' ', $this->get_table_classes() )); ?>">
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

        // Add pagination below the table
        $this->display_tablenav( 'bottom' );
    }

    protected function get_table_classes() {
        $mode = get_user_setting( 'posts_list_mode', 'list' );

        $mode_class = esc_attr( 'table-view-' . $mode );

        return array( 'widefat', 'striped', $mode_class, esc_attr($this->_args['plural']) );
    }

}