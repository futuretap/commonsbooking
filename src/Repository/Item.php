<?php

namespace CommonsBooking\Repository;

use CommonsBooking\Wordpress\CustomPostType\Timeframe;

class Item extends BookablePost
{
    /**
     * Get all Locations current user is allowed to see/edit
     * @return array
     */
    public static function getByCurrentUser() {
        $current_user = wp_get_current_user();
        $items = [];

        // Get all Locations where current user is author
        $args = array(
            'post_type' => \CommonsBooking\Wordpress\CustomPostType\Item::$postType,
            'author' => $current_user->ID
        );
        $query = new \WP_Query($args);
        if ($query->have_posts()) {
            $items = array_merge($items, $query->get_posts());
        }

        // get all items where current user is assigned as admin
        $args = array(
            'post_type' => \CommonsBooking\Wordpress\CustomPostType\Item::$postType,
            'meta_query'  => array(
                'relation' => 'AND',
                array(
                    'key'   => '_' . \CommonsBooking\Wordpress\CustomPostType\Item::$postType . '_admins',
                    'value' => '"' . $current_user->ID . '"',
                    'compare' => 'like'
                )
            )
        );

         // workaround: if user has admin-role get all available items
        // TODO: better solution to check if user has administrator role
        if ( in_array( 'administrator', $current_user->roles ) ) {
            unset($args);
            $args = array(
                'post_type' => \CommonsBooking\Wordpress\CustomPostType\Item::$postType,
            );
        }


        $query = new \WP_Query($args);
        if ($query->have_posts()) {
            $items = array_merge($items, $query->get_posts());
        }

        return $items;
    }

    /**
     * Returns array with items at location.
     *
     * @param $locationId
     *
     * @param bool $bookable
     *
     * @return array
     * @throws \Exception
     */
    public static function getByLocation($locationId, $bookable = false) {
        if($locationId instanceof \WP_Post) {
            $locationId = $locationId->ID;
        }
        $items = [];
        $itemIds = [];

        $args = array(
            'post_type' => Timeframe::getPostType(),
            'post_status' => array('confirmed', 'unconfirmed', 'publish', 'inherit'),
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'location-id',
                    'value' => $locationId
                )
            )
        );

        $query = new \WP_Query($args);
        if ($query->have_posts()) {
            $timeframes = $query->get_posts();
            foreach ($timeframes as $timeframe) {
                $itemId = get_post_meta($timeframe->ID, 'item-id', true);

                if($itemId && !in_array($itemId, $itemIds)) {
                    $itemIds[] = $itemId;
                    $item = get_post($itemId);
                    // add only published items
                    if($item->post_status == 'publish') {
                        $items[] = $item;
                    }
                }
            }
        }

        foreach($items as $key => &$item) {
            $item = new \CommonsBooking\Model\Item($item);

            // If items shall be bookable, we need to check...
            if($bookable && !$item->getBookableTimeframesByLocation($locationId)) {
                unset($items[$key]);
            }
        }

        return $items;
    }

    /**
     * @return mixed
     */
    protected static function getPostType()
    {
        return \CommonsBooking\Wordpress\CustomPostType\Item::getPostType();
    }

    /**
     * @return string
     */
    protected static function getModelClass()
    {
        return \CommonsBooking\Model\Item::class;
    }
}
