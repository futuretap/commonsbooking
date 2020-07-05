<?php

use CommonsBooking\CB\CB; 

foreach ($templateData['items'] as $item) {
    ?>
    <div class="cb-item-wrapper cb-box">
        <h2 class="cb-big"><a href="<?php echo get_permalink($item->ID); ?>"><?php echo $item->post_title; ?></a></h2>
        <div class="cb-excerpt">
            <div class="cb-thumb">
                <?php echo get_the_post_thumbnail($item->ID, 'thumbnail'); ?>
            </div>
                <?php echo $item->post_content; ?>
        </div>
        <div class="cb-table">
            <?php
            foreach ($item->getBookableTimeFrames() as $bookableTimeFrame) {
                $startDateTimestamp = intval(get_post_meta($bookableTimeFrame->ID, 'repetition-start', true));
                $endDateTimestamp = intval(get_post_meta($bookableTimeFrame->ID, 'repetition-end', true));

                $locationId = get_post_meta($bookableTimeFrame->ID, 'location-id', true);
                $location = get_post($locationId);
                $bookingUrl = get_permalink($locationId) . "&item=" . $item->ID;
                $dateString = ($endDateTimestamp ? "" : "Ab ") . date(get_option('date_format'),
                        $startDateTimestamp);
                if ($endDateTimestamp) {
                    $dateString .= " - " . date(get_option('date_format'), $endDateTimestamp);
                }
                ?>
                <div class="cb-row">
                    <a href="<?php echo $bookingUrl; ?>" class="cb-button align-right"> Hier buchen</a>
                    <span class="cb-date"><?php echo $dateString; ?></span>
                    <span class="cb-location-name"><?php echo $location->post_title; ?></span>
                    <span class="cb-address"><?php echo CB::get('location', 'adress', $location->ID); ?> </span>
                </div>
            <?php
            }
            ?>
        </div>
    </div>
    <?php
}

?>

