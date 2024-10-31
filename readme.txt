# NMR Strava activities

Contributors: mirceatm
Donate link: https://paypal.me/mirceatm
Tags: strava, virtual, import, activities
Requires at least: 5.2
Tested up to: 6.3.1
Stable tag: 1.0.6
License: GPLv2 or later
License URI: <https://www.gnu.org/licenses/gpl-2.0.html>

== Description ==

Import Strava activities to your website. Get notified immediately after an Strava activity is recorded, using webhooks.
Strava webhooks: <https://developers.strava.com/docs/webhooks/>

You need to setup a Strava API Application: <https://www.strava.com/settings/api>

Add this shortcode: **[strava_nmr]** to a user facing page or post.

The address of that page/post must be used on setting: Redirect URI (see below)

Configure NMR Strava activities plugin on your admin interface: **Settings: Strava NMR**

* Strava client id - number from Strava API Application
* Strava client secret - secret from Strava API Application
* Redirect URI - Address of a page/post on your website where the shortcode: [strava_nmr] is used.
* Webhook callback url - it's determined automatically, should be: <https://your-website.com/wp-admin/admin-ajax.php?action=nmr-strava-callback&>
    Notice the ampersand: & at the end - it should be there.
* Verify token - a string used in the webhook subscription process

Press **Activate Strava Webhook**

On success, you'll see the message on **Plugin status**:
> Strava webhook subscription id = 109463

it means webhook subscription worked.

Strava will push activity data for all Strava users that connected their account on your website with their Strava account.
Received activities are stored locally on your wordpress database, and an event with strava activity data is raised.

`do_action('strava_nmr_activity_changed', 'update', $activity_data);`

where `$activity_data` is an array.

A Theme or another Plugin might listen to this action and perform additional actions.

This plugin reacts to new, changed or deleted activities.

Determine what activites are imported by interacting with `nmr_strava_save_activity` filter
`
function on_nmr_strava_save_activity($save_nmr_strava_activity, $activity_type){
    // we only want to import running activities
    if(strcasecmp('run', $activity_type) == 0
        || strcasecmp('virtualrun', $activity_type) == 0){
            return true;
    }
    return false;
}
add_filter('nmr_strava_save_activity', 'on_nmr_strava_save_activity', 10, 2);
`

There's an additional filter called `nmr_strava_save_activity_full` that sends the entire data received from Strava as an array that functions like the one above.

List of Strava activities:  AlpineSki, BackcountrySki, Canoeing, Crossfit, EBikeRide, Elliptical, Golf, Handcycle, Hike, IceSkate, InlineSkate, Kayaking, Kitesurf, NordicSki, Ride, RockClimbing, RollerSki, Rowing, Run, Sail, Skateboard, Snowboard, Snowshoe, Soccer, StairStepper, StandUpPaddling, Surfing, Swim, Velomobile, VirtualRide, VirtualRun, Walk, WeightTraining, Wheelchair, Windsurf, Workout, Yoga

You may list the first 100 or so activities received from Strava in any page or post by using this shortcode: `[strava_nmr_table top="100"]`

If you enjoy using *NMR Strava activities* and find it useful, please consider [__making a donation__](https://paypal.me/mirceatm). Your donation will help encourage and support the plugin's continued development and better user support.

= Privacy Notices =

This plugin stores data collected from Strava, which may include the submitters' personal information, in the database on the server that hosts the website.

== Installation ==

1. Upload the entire `strava-activities-nmr` folder to the `/wp-content/plugins/` directory.
1. Activate the plugin through the 'Plugins' menu in WordPress.

== Frequently Asked Questions ==

== Screenshots ==

![Strava activities](screenshot-1.jpg)
![Strava activities NMR plugin](screenshot-2.jpg)

== Changelog ==

= 1.0.6 =

- Added `nmr_strava_save_activity_full` filter that sends the entire Strava data as array. One can use it to filter out manual activities, for instance.
- Remove dangling options by the name `nmr-strava-%`
- Save `subscription_id` once we read it from Strava

= 1.0.5 =

- Added top property to shortcode `[strava_nmr_table top=10]`. Default value if 100.
- `[strava_nmr_table top=10]` shows km and minutes instead of meters and seconds.
- Activate Strava will also save the settings.

= 1.0.4 =

- Added simple shortcode to list activities received from Strava: `[strava_nmr_table]`

= 1.0.3 =

- Store Strava username, firstname, lastname and profile link
- Delete duplicate rows

= 1.0.2 =

- Fixed strava activity import when there is no associated wordpress user.
- Add filter `nmr_strava_save_activity`

= 1.0.1 =

- Fixed option save
- Add button to deactivate Strava subscription
- Removed use of PHP session
- Allow Strava activities from anonymous visitors (un-registered users) 

= 1.0.0 =

* Initial version.
