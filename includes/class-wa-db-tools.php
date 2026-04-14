<?php
defined( 'ABSPATH' ) || exit;

/**
 * Database query tools for the AI Agent.
 * Each method enforces group access and returns a raw array for the AI to process.
 * Group filtering is always enforced in PHP — the AI cannot bypass it.
 */
class INPURSUIT_WA_DB_Tools {

    // -------------------------------------------------------------------------
    // Tools
    // -------------------------------------------------------------------------

    /**
     * Tool: get_members
     * Filter by group, status, gender, location, limit.
     */
    public static function get_members( $params, $wp_user = null ) {
        $limit     = min( (int) ( $params['limit'] ?? 15 ), 30 );
        $tax_query = self::group_tax_query( $wp_user );

        // Additional filter: group
        if ( ! empty( $params['group'] ) ) {
            $group_term = get_term_by( 'name', $params['group'], 'inpursuit-group' )
                       ?: get_term_by( 'slug', sanitize_title( $params['group'] ), 'inpursuit-group' );
            if ( $group_term ) {
                $user_ids = self::get_user_group_ids( $wp_user );
                if ( empty( $user_ids ) || in_array( $group_term->term_id, $user_ids, true ) ) {
                    $tax_query = array(
                        array(
                            'taxonomy' => 'inpursuit-group',
                            'field'    => 'id',
                            'terms'    => array( $group_term->term_id ),
                        ),
                    );
                }
            }
        }

        // Additional filter: status
        if ( ! empty( $params['status'] ) ) {
            $term = get_term_by( 'name', $params['status'], 'inpursuit-status' )
                 ?: get_term_by( 'slug', sanitize_title( $params['status'] ), 'inpursuit-status' );
            if ( $term ) {
                $tax_query[] = array(
                    'taxonomy' => 'inpursuit-status',
                    'field'    => 'id',
                    'terms'    => array( $term->term_id ),
                );
            }
        }

        // Additional filter: gender
        if ( ! empty( $params['gender'] ) ) {
            $term = get_term_by( 'name', $params['gender'], 'inpursuit-gender' )
                 ?: get_term_by( 'slug', sanitize_title( $params['gender'] ), 'inpursuit-gender' );
            if ( $term ) {
                $tax_query[] = array(
                    'taxonomy' => 'inpursuit-gender',
                    'field'    => 'id',
                    'terms'    => array( $term->term_id ),
                );
            }
        }

        // Additional filter: location
        if ( ! empty( $params['location'] ) ) {
            $term = get_term_by( 'name', $params['location'], 'inpursuit-location' )
                 ?: get_term_by( 'slug', sanitize_title( $params['location'] ), 'inpursuit-location' );
            if ( $term ) {
                $tax_query[] = array(
                    'taxonomy' => 'inpursuit-location',
                    'field'    => 'id',
                    'terms'    => array( $term->term_id ),
                );
            }
        }

        $args = array(
            'post_type'      => INPURSUIT_MEMBERS_POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'title',
            'order'          => 'ASC',
        );
        if ( ! empty( $tax_query ) ) {
            $args['tax_query'] = $tax_query;
        }

        $members = get_posts( $args );

        $result = array();
        foreach ( $members as $m ) {
            $result[] = array(
                'id'     => $m->ID,
                'name'   => $m->post_title,
                'status' => self::get_term( $m->ID, 'inpursuit-status' ),
                'group'  => self::get_term( $m->ID, 'inpursuit-group' ),
            );
        }

        return array( 'count' => count( $result ), 'members' => $result );
    }

    /**
     * Tool: get_member_details
     * Full profile for a single member by name (with group access check).
     */
    public static function get_member_details( $params, $wp_user = null ) {
        $name = sanitize_text_field( $params['name'] ?? '' );
        if ( empty( $name ) ) {
            return array( 'error' => 'name parameter is required.' );
        }

        $member = self::resolve_single_member( $name, $wp_user );
        if ( isset( $member['error'] ) ) {
            return $member;
        }

        $id        = $member['id'];
        $dates_db  = INPURSUIT_DB_MEMBER_DATES::getInstance();

        return array(
            'id'         => $id,
            'name'       => $member['name'],
            'status'     => self::get_term( $id, 'inpursuit-status' ),
            'group'      => self::get_term( $id, 'inpursuit-group' ),
            'gender'     => self::get_term( $id, 'inpursuit-gender' ),
            'profession' => self::get_term( $id, 'inpursuit-profession' ),
            'location'   => self::get_term( $id, 'inpursuit-location' ),
            'age'        => $dates_db->age( $id ),
            'last_seen'  => self::get_last_seen( $id ),
        );
    }

    /**
     * Tool: get_member_history
     * Recent comments and events attended for a member (group access enforced).
     */
    public static function get_member_history( $params, $wp_user = null ) {
        global $wpdb;

        $name = sanitize_text_field( $params['name'] ?? '' );
        if ( empty( $name ) ) {
            return array( 'error' => 'name parameter is required.' );
        }

        $member = self::resolve_single_member( $name, $wp_user );
        if ( isset( $member['error'] ) ) {
            return $member;
        }

        $member_id     = $member['id'];
        $comment_table = $wpdb->prefix . 'ip_comments';
        $rel_table     = $wpdb->prefix . 'ip_event_member_relation';

        $comments = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.comment, c.created_at FROM {$comment_table} c
             WHERE c.post_id = %d ORDER BY c.created_at DESC LIMIT 10",
            $member_id
        ) );

        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.post_title AS event_name, p.post_date AS event_date
             FROM {$rel_table} r
             INNER JOIN {$wpdb->posts} p ON p.ID = r.event_id AND p.post_status = 'publish'
             WHERE r.member_id = %d ORDER BY p.post_date DESC LIMIT 10",
            $member_id
        ) );

        return array(
            'member'   => $member['name'],
            'comments' => array_map( function( $c ) {
                return array( 'text' => $c->comment, 'date' => $c->created_at );
            }, $comments ),
            'events'   => array_map( function( $e ) {
                return array( 'name' => $e->event_name, 'date' => $e->event_date );
            }, $events ),
        );
    }

    /**
     * Tool: get_stats
     * Overall totals, breakdown by status and group.
     */
    public static function get_stats( $params, $wp_user = null ) {
        global $wpdb;

        $user_ids = self::get_user_group_ids( $wp_user );

        // Total members — scoped to user's groups if restricted
        if ( ! empty( $user_ids ) ) {
            $placeholders  = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
            $query_args    = array_merge( array( INPURSUIT_MEMBERS_POST_TYPE ), $user_ids );
            $total_members = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                   AND tt.taxonomy = 'inpursuit-group'
                 WHERE p.post_type = %s AND p.post_status = 'publish'
                   AND tt.term_id IN ($placeholders)",
                $query_args
            ) );
        } else {
            $total_members = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
                INPURSUIT_MEMBERS_POST_TYPE
            ) );
        }

        $total_events = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            INPURSUIT_EVENTS_POST_TYPE
        ) );

        // Status breakdown — scoped to user's groups if restricted
        $statuses         = get_terms( array( 'taxonomy' => 'inpursuit-status', 'hide_empty' => false ) );
        $status_breakdown = array();
        if ( ! is_wp_error( $statuses ) ) {
            foreach ( $statuses as $t ) {
                $count_args = array(
                    'post_type'      => INPURSUIT_MEMBERS_POST_TYPE,
                    'post_status'    => 'publish',
                    'fields'         => 'ids',
                    'posts_per_page' => -1,
                    'tax_query'      => array(
                        array( 'taxonomy' => 'inpursuit-status', 'field' => 'id', 'terms' => $t->term_id ),
                    ),
                );
                if ( ! empty( $user_ids ) ) {
                    $count_args['tax_query'][] = array(
                        'taxonomy' => 'inpursuit-group',
                        'field'    => 'id',
                        'terms'    => $user_ids,
                    );
                }
                $count = count( get_posts( $count_args ) );
                if ( $count > 0 || empty( $user_ids ) ) {
                    $status_breakdown[] = array( 'name' => $t->name, 'count' => $count );
                }
            }
        }

        // Group breakdown — restricted to user's accessible groups
        $groups          = get_terms( array( 'taxonomy' => 'inpursuit-group', 'hide_empty' => true ) );
        $group_breakdown = array();
        if ( ! is_wp_error( $groups ) ) {
            foreach ( $groups as $t ) {
                if ( ! empty( $user_ids ) && ! in_array( $t->term_id, $user_ids, true ) ) {
                    continue;
                }
                $group_breakdown[] = array( 'name' => $t->name, 'count' => $t->count );
            }
        }

        return array(
            'total_members' => $total_members,
            'total_events'  => $total_events,
            'by_status'     => $status_breakdown,
            'by_group'      => $group_breakdown,
        );
    }

    /**
     * Tool: get_followup_members
     * Members with pending/follow-up status (with group filter).
     */
    public static function get_followup_members( $params, $wp_user = null ) {
        $status_terms = get_terms( array( 'taxonomy' => 'inpursuit-status', 'hide_empty' => false ) );
        if ( is_wp_error( $status_terms ) || empty( $status_terms ) ) {
            return array( 'error' => 'No member statuses found.' );
        }

        $followup_keywords = array( 'pending', 'follow', 'new', 'inactive' );
        $followup_term_ids = array();
        foreach ( $status_terms as $term ) {
            foreach ( $followup_keywords as $kw ) {
                if ( stripos( $term->slug, $kw ) !== false || stripos( $term->name, $kw ) !== false ) {
                    $followup_term_ids[] = $term->term_id;
                    break;
                }
            }
        }

        if ( empty( $followup_term_ids ) ) {
            return array( 'error' => 'No follow-up status terms found.' );
        }

        $tax_query   = self::group_tax_query( $wp_user );
        $tax_query[] = array(
            'taxonomy' => 'inpursuit-status',
            'terms'    => $followup_term_ids,
        );

        $members = get_posts( array(
            'post_type'      => INPURSUIT_MEMBERS_POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 30,
            'tax_query'      => $tax_query,
        ) );

        $result = array();
        foreach ( $members as $m ) {
            $result[] = array(
                'id'     => $m->ID,
                'name'   => $m->post_title,
                'status' => self::get_term( $m->ID, 'inpursuit-status' ),
                'group'  => self::get_term( $m->ID, 'inpursuit-group' ),
            );
        }

        return array( 'count' => count( $result ), 'members' => $result );
    }

    /**
     * Tool: get_events
     * Birthdays and anniversaries remaining this month.
     */
    public static function get_events( $params, $wp_user = null ) {
        global $wpdb;

        $table     = $wpdb->prefix . 'ip_member_dates';
        $month     = (int) date( 'm' );
        $today_day = (int) date( 'd' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT md.member_id, md.event_type, md.event_date, p.post_title AS member_name
             FROM {$table} md
             INNER JOIN {$wpdb->posts} p ON p.ID = md.member_id
             WHERE p.post_status = 'publish' AND p.post_type = %s
               AND MONTH( md.event_date ) = %d AND DAY( md.event_date ) >= %d
             ORDER BY DAY( md.event_date ) ASC",
            INPURSUIT_MEMBERS_POST_TYPE, $month, $today_day
        ) );

        $user_ids = self::get_user_group_ids( $wp_user );
        $result   = array();
        foreach ( $rows as $row ) {
            if ( ! empty( $user_ids ) ) {
                $member_groups = wp_get_post_terms( $row->member_id, 'inpursuit-group', array( 'fields' => 'ids' ) );
                if ( empty( array_intersect( $user_ids, $member_groups ) ) ) {
                    continue;
                }
            }
            $result[] = array(
                'member'     => $row->member_name,
                'event_type' => $row->event_type,
                'date'       => date( 'd M', strtotime( $row->event_date ) ),
            );
        }

        return array( 'month' => date( 'F Y' ), 'count' => count( $result ), 'events' => $result );
    }

    /**
     * Tool: get_event_attendance
     * Attendance stats for a named event.
     */
    public static function get_event_attendance( $params, $wp_user = null ) {
        global $wpdb;

        $name = sanitize_text_field( $params['event_name'] ?? '' );
        if ( empty( $name ) ) {
            return array( 'error' => 'event_name parameter is required.' );
        }

        $like   = '%' . $wpdb->esc_like( $name ) . '%';
        $result = $wpdb->get_row( $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status = 'publish' AND post_title LIKE %s
             ORDER BY post_date DESC LIMIT 1",
            INPURSUIT_EVENTS_POST_TYPE, $like
        ) );

        if ( ! $result ) {
            return array( 'error' => "No event found matching \"{$name}\"." );
        }

        $event_db = INPURSUIT_DB_EVENT::getInstance();

        return array(
            'event'      => $result->post_title,
            'date'       => get_the_date( 'd M Y', $result->ID ),
            'registered' => $event_db->numberOfRegisteredMembers( $result->ID ),
            'attended'   => $event_db->numberOfParticipatingMembers( $result->ID ),
            'percentage' => $event_db->attendantsPercentage( $result->ID ),
        );
    }

    /**
     * Tool: add_member_comment
     * Save a comment (with optional category). Group access enforced.
     */
    public static function add_member_comment( $params, $wp_user = null ) {
        global $wpdb;

        $member_name   = sanitize_text_field( $params['member_name'] ?? '' );
        $comment_text  = sanitize_textarea_field( $params['comment_text'] ?? '' );
        $category_name = sanitize_text_field( $params['category_name'] ?? '' );

        if ( empty( $member_name ) || empty( $comment_text ) ) {
            return array( 'error' => 'member_name and comment_text are required.' );
        }

        $member = self::resolve_single_member( $member_name, $wp_user );
        if ( isset( $member['error'] ) ) {
            return $member;
        }

        $comment_db = INPURSUIT_DB_COMMENT::getInstance();
        $comment_id = $comment_db->insert( array(
            'comment' => $comment_text,
            'post_id' => (int) $member['id'],
            'user_id' => $wp_user ? (int) $wp_user->ID : 0,
        ) );

        if ( ! $comment_id ) {
            return array( 'error' => 'Failed to save comment.' );
        }

        $category_saved = null;
        if ( ! empty( $category_name ) ) {
            $cat_db    = INPURSUIT_DB_COMMENTS_CATEGORY::getInstance();
            $cat_table = $cat_db->getTable();
            $cat_row   = $wpdb->get_row( $wpdb->prepare(
                "SELECT term_id, name FROM {$cat_table} WHERE LOWER(name) = LOWER(%s) LIMIT 1",
                $category_name
            ) );
            if ( $cat_row ) {
                INPURSUIT_DB_COMMENTS_CATEGORY_RELATION::getInstance()->insert( array(
                    'term_id'    => (int) $cat_row->term_id,
                    'comment_id' => (int) $comment_id,
                ) );
                $category_saved = $cat_row->name;
            }
        }

        return array(
            'success'  => true,
            'member'   => $member['name'],
            'category' => $category_saved,
        );
    }

    /**
     * Tool: get_comment_categories
     * List all available comment categories.
     */
    public static function get_comment_categories( $params, $wp_user = null ) {
        $cat_db = INPURSUIT_DB_COMMENTS_CATEGORY::getInstance();
        $rows   = $cat_db->get_results( $cat_db->getResultsQuery( array() ) );

        return array(
            'categories' => array_map( function( $r ) {
                return array( 'id' => $r->term_id, 'name' => $r->name );
            }, (array) $rows ),
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function get_user_group_ids( $wp_user ) {
        if ( ! $wp_user ) {
            return array();
        }
        $ids = get_user_meta( $wp_user->ID, 'inpursuit-group', true );
        return ( is_array( $ids ) && ! empty( $ids ) ) ? array_map( 'intval', $ids ) : array();
    }

    private static function group_tax_query( $wp_user ) {
        $ids = self::get_user_group_ids( $wp_user );
        if ( empty( $ids ) ) {
            return array();
        }
        return array(
            array(
                'taxonomy' => 'inpursuit-group',
                'field'    => 'id',
                'terms'    => $ids,
            ),
        );
    }

    private static function resolve_single_member( $name, $wp_user ) {
        global $wpdb;

        $like     = '%' . $wpdb->esc_like( $name ) . '%';
        $user_ids = self::get_user_group_ids( $wp_user );

        $tax_join  = '';
        $tax_where = '';
        if ( ! empty( $user_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
            $tax_join     = "INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'inpursuit-group'";
            $tax_where    = $wpdb->prepare( " AND tt.term_id IN ($placeholders)", $user_ids );
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title FROM {$wpdb->posts} p $tax_join
             WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_title LIKE %s $tax_where
             LIMIT 5",
            INPURSUIT_MEMBERS_POST_TYPE, $like
        ) );

        if ( empty( $rows ) ) {
            return array( 'error' => "No member found matching \"{$name}\"." );
        }
        if ( count( $rows ) > 1 ) {
            return array(
                'error'   => 'Multiple members found. Be more specific.',
                'matches' => wp_list_pluck( $rows, 'post_title' ),
            );
        }

        return array( 'id' => $rows[0]->ID, 'name' => $rows[0]->post_title );
    }

    private static function get_term( $post_id, $taxonomy ) {
        $terms = get_the_terms( $post_id, $taxonomy );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return null;
        }
        return $terms[0]->name;
    }

    private static function get_last_seen( $member_id ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'ip_event_member_relation';
        $event_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT r.event_id FROM {$table} r
             INNER JOIN {$wpdb->posts} p ON p.ID = r.event_id
             WHERE r.member_id = %d AND p.post_status = 'publish'
             ORDER BY p.post_date DESC LIMIT 1",
            $member_id
        ) );
        if ( ! $event_id ) {
            return null;
        }
        return get_the_date( 'd M Y', $event_id ) . ' — ' . get_the_title( $event_id );
    }
}
