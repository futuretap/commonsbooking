<?php

namespace CommonsBooking\Model;

/**
 * Class Timeframe
 * @package CommonsBooking\Model
 */
class Timeframe extends CustomPost
{
    /**
     * Return residence in a human readable format
     *
     * "From xx.xx.",  "Until xx.xx.", "From xx.xx. until xx.xx.", "no longer available"
     *
     * @return string
     */
    public function formattedBookableDate()
    {
        $format = self::getDateFormat();

        //  workaround because we need to calculate, and getMeta returns empty *string* if not set
        $startDate = $this->getStartDate() ? $this->getStartDate() : 0;
        $endDate = $this->getEndDate() ? $this->getEndDate() : 0;
        $today = strtotime('now');

        $startDateFormatted = date($format, $startDate);
        $endDateFormatted = date($format, $endDate);

        $label = __('Available here', 'commonsbooking');
        $availableString = '';

        if ($startDate !== 0 && $endDate !== 0 && $startDate == $endDate) { // available only one day
            $availableString = sprintf(__('on %s', 'commonsbooking'), $startDateFormatted);
        } elseif ($startDate > 0 && ($endDate == 0)) { // start but no end date
            if ($startDate > $today) { // start is in the future
                $availableString = sprintf(__('from %s', 'commonsbooking'), $startDateFormatted);
            } else { // start has passed, no end date, probably a fixed location
                $availableString = __('permanently', 'commonsbooking');
            }
        } elseif ($startDate > 0 && $endDate > 0) { // start AND end date
            if ($startDate > $today) { // start is in the future, with an end date
                $availableString = sprintf(__(' from %s until %s', 'commonsbooking'), $startDateFormatted,
                    $endDateFormatted);
            } else { // start has passed, with an end date
                $availableString = sprintf(__(' until %s', 'commonsbooking'), $endDateFormatted);
            }
        }

        return $label . ' ' . $availableString;
    }

    /**
     * Return date format
     *
     * @return string
     */
    public function getDateFormat()
    {
        return get_option('date_format');
    }

    /**
     * Return Start (repetition) date
     *
     * @return string
     */
    public function getStartDate()
    {
        return self::getMeta('repetition-start');
    }

    /**
     * Return End (repetition) date
     *
     * @return string
     */
    public function getEndDate()
    {
        return self::getMeta('repetition-end');
    }

    /**
     * Return  time format
     *
     * @return string
     */
    public function getTimeFormat()
    {
        return get_option('time_format');
    }

    /**
     * Validates if there can be booking codes created for this timeframe.
     * @return bool
     * @throws \Exception
     */
    public function bookingCodesApplieable()
    {
        return $this->getLocation() && $this->getItem() &&
               $this->getStartDate() && $this->getEndDate() &&
               $this->getType() == \CommonsBooking\Wordpress\CustomPostType\Timeframe::BOOKABLE_ID;
    }

    /**
     * @return Location
     * @throws \Exception
     */
    public function getLocation()
    {
        $locationId = self::getMeta('location-id');
        if ($post = get_post($locationId)) {
            return new Location($post);
        }

        return $post;
    }

    /**
     * @return Item
     * @throws \Exception
     */
    public function getItem()
    {
        $itemId = self::getMeta('item-id');

        if ($post = get_post($itemId)) {
            return new Item($post);
        }

        return $post;
    }

    /**
     * Returns type id
     * @return mixed
     */
    public function getType()
    {
        return self::getMeta('type');
    }

    /**
     * Checks if Timeframe is valid.
     * @return bool
     * @throws \Exception
     */
    public function isValid()
    {
        if (
            $this->getType() == \CommonsBooking\Wordpress\CustomPostType\Timeframe::BOOKABLE_ID &&
            $this->getLocation() &&
            $this->getItem() &&
            $this->getStartDate()
        ) {
            $postId = $this->ID;

            if ($this->getStartTime() && ! $this->getEndTime()) {
                set_transient("timeframeValidationFailed",
                    __("A pickup time but no return time has been set. Please set the return time.", 'commonsbooking'),
                    45);

                return false;
            }

            // Get Timeframes with same location, item and a startdate
            $existingTimeframes = \CommonsBooking\Repository\Timeframe::get(
                [$this->getLocation()->ID],
                [$this->getItem()->ID],
                [\CommonsBooking\Wordpress\CustomPostType\Timeframe::BOOKABLE_ID],
                null,
                true
            );

            // filter current timeframe
            $existingTimeframes = array_filter($existingTimeframes, function ($timeframe) use ($postId) {
                return $timeframe->ID !== $postId && $timeframe->getStartDate();
            });

            // Validate against existing other timeframes
            foreach ($existingTimeframes as $timeframe) {

                // check if timeframes overlap
                if (
                $this->hasTimeframeDateOverlap($this, $timeframe)
                ) {
                    // Compare grid types
                    if ($timeframe->getGrid() != $this->getGrid()) {
                        set_transient("timeframeValidationFailed",
                            #translators: first %s = timeframe-ID, second %s is timeframe post_title
                            sprintf(__('Overlapping bookable timeframes are only allowed to have the same grid. See overlapping timeframe ID: %s: %s',
                                'commonsbooking', 5), $timeframe->ID, $timeframe->post_title));

                        return false;
                    }

                    // Check if day slots overlap
                    if ($this->hasTimeframeTimeOverlap($this, $timeframe)) {
                        set_transient("timeframeValidationFailed",
                            #translators: first %s = timeframe-ID, second %s is timeframe post_title
                            sprintf(__('time periods are not allowed to overlap. Please check the other timeframe to avoid overlapping time periods during one specific day.. See affected timeframe ID: %s: %s',
                                'commonsbooking', 5), $timeframe->ID, $timeframe->post_title));

                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Returns start time for day-slots.
     * @return mixed
     */
    public function getStartTime()
    {
        return self::getMeta('start-time');
    }

    /**
     * Returns end time for day-slots.
     * @return mixed
     */
    public function getEndTime()
    {
        return self::getMeta('end-time');
    }

    /**
     * Checks if timeframes are overlapping in date range.
     *
     * @param $timeframe1
     * @param $timeframe2
     *
     * @return bool
     */
    protected function hasTimeframeDateOverlap($timeframe1, $timeframe2)
    {
        return
            ! $timeframe1->getEndDate() && ! $timeframe2->getEndDate() ||
            (
                $timeframe1->getEndDate() && ! $timeframe2->getEndDate() &&
                $timeframe2->getStartDate() <= $timeframe1->getEndDate() &&
                $timeframe2->getStartDate() >= $timeframe1->getStartDate()
            ) ||
            (
                ! $timeframe1->getEndDate() && $timeframe2->getEndDate() &&
                $timeframe2->getEndDate() > $timeframe1->getStartDate()
            ) ||
            (
                $timeframe1->getEndDate() && $timeframe2->getEndDate() &&
                (
                    ($timeframe1->getEndDate() > $timeframe2->getStartDate() && $timeframe1->getEndDate() < $timeframe2->getEndDate()) ||
                    ($timeframe2->getEndDate() > $timeframe1->getStartDate() && $timeframe2->getEndDate() < $timeframe1->getEndDate())
                )
            );
    }

    /**
     * Returns grit type id
     * @return mixed
     */
    public function getGrid()
    {
        return self::getMeta('grid');
    }

    /**
     * Checks if timeframes are overlapping in daily slots.
     *
     * @param $timeframe1
     * @param $timeframe2
     *
     * @return bool
     */
    protected function hasTimeframeTimeOverlap($timeframe1, $timeframe2)
    {
        return
            ! strtotime($timeframe1->getEndTime()) && ! strtotime($timeframe2->getEndTime()) ||
            (
                strtotime($timeframe1->getEndTime()) && ! strtotime($timeframe2->getEndTime()) &&
                strtotime($timeframe2->getStartTime()) <= strtotime($timeframe1->getEndTime()) &&
                strtotime($timeframe2->getStartTime()) >= strtotime($timeframe1->getStartTime())
            ) ||
            (
                ! strtotime($timeframe1->getEndTime()) && strtotime($timeframe2->getEndTime()) &&
                strtotime($timeframe2->getEndTime()) > strtotime($timeframe1->getStartTime())
            ) ||
            (
                strtotime($timeframe1->getEndTime()) && strtotime($timeframe2->getEndTime()) &&
                (
                    (strtotime($timeframe1->getEndTime()) > strtotime($timeframe2->getStartTime()) && strtotime($timeframe1->getEndTime()) < strtotime($timeframe2->getEndTime())) ||
                    (strtotime($timeframe2->getEndTime()) > strtotime($timeframe1->getStartTime()) && strtotime($timeframe2->getEndTime()) < strtotime($timeframe1->getEndTime()))
                )
            );
    }

}
