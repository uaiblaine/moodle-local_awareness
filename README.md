[![ci](https://github.com/uaiblaine/moodle-local_awareness/actions/workflows/ci.yml/badge.svg)](https://github.com/uaiblaine/moodle-local_awareness/actions/workflows/ci.yml)

# Awareness

Awareness is a Moodle local plugin to display policy, compliance, communication, and onboarding notices in modal form.

The plugin supports mandatory acknowledgement workflows, optional forced logout, audience targeting, and reporting for dismissed and acknowledged interactions.

## Project origin and credits

This project is a derivative fork based on:

https://github.com/catalyst/moodle-local_sitenotice

It has been adapted and evolved with a new direction under the Awareness name.

## Feature overview

- Site-wide modal notices.
- Optional mandatory acknowledgement before users can continue.
- Optional force logout after dismissal or acknowledgement, depending on settings.
- Audience targeting by cohort.
- Optional requirement to complete a selected course before the notice stops appearing.
- URL path matching to scope notices to specific pages.
- Scheduling via start date and expiry date.
- Perpetual notices (always active while enabled).
- Recurring re-display using reset intervals.
- Hyperlink extraction and click tracking from notice content.
- Reports for acknowledgements and dismissals with export options.
- Optional clean-up of related tracking/interaction data when deleting notices.
- Optional modal background image.
- Optional modal dimensions (width and height).
- Outside-click close behavior control.
- Session-based optimization through cached user notice resolution.

## Compatibility

This repository is maintained for modern Moodle branches through CI matrix testing. Check the CI badge and workflow matrix for currently validated combinations.

## Administration paths

- Settings: Site administration > Awareness > Settings
- Management: Site administration > Awareness > Manage notice

## Configuration

### Enable plugin
- Setting: Enabled
- Effect: enables Awareness notices site-wide.

### Allow notice update
- Setting: Allow notice update
- Effect: permits editing existing notices.

### Allow notice deletion
- Setting: Allow notice deletion
- Effect: permits deleting notices.

### Clean up deleted notice data
- Setting: Clean up info related to the deleted notice
- Effect: when deletion is allowed, removes related records such as hyperlinks, hyperlink history, acknowledgements, and last view records.

## Notice authoring

When creating or editing a notice, you can configure:

- Title and rich content.
- Start/end window and perpetual mode.
- Reset interval.
- Force logout behavior.
- Acknowledgement requirement.
- Cohort visibility.
- Required course completion.
- URL path matching.
- Modal presentation options (background image, width, height, outside click).

## Reports

From Manage notice, each notice provides access to:

- Acknowledgement report.
- Dismiss report.

Reports include filtering and downloadable exports.

## Development notes

- PHPUnit and Behat tests are available under tests.
- CI uses reusable Moodle plugin workflows.
- For release automation, tags matching `v*` trigger the Moodle Plugin Release workflow.

## Contributing

Issues and pull requests are welcome:

https://github.com/uaiblaine/moodle-local_awareness/issues
