@local @local_awareness @javascript
Feature: The editor tells the truth about a notice that reaches nobody
  In order to notice that a published notice is not actually being shown
  As an administrator
  I need the page to say so rather than paint a green badge over it

  Background:
    Given the following config values are set as admin:
      | allow_update | 1 | local_awareness |
    And I log in as "admin"

  Scenario: A published notice whose window has closed says so
    # The badge read "Live · being shown" from the enabled flag alone, which is a true statement
    # about the flag and a false one about the world: is_within_active_window() has been refusing
    # this notice since the expiry passed. Nothing on the page said it.
    Given the following site notices exist
      | title              | content            | enabled | timestart  | timeend    |
      | Expired notice     | <p>Body</p>        | 1       | 1600000000 | 1600086400 |
    When I navigate to "Awareness > Manage" in site administration
    And I click on "Edit" "link" in the "Expired notice" "table_row"
    Then I should see "Published · nobody is seeing it"
    And I should see "This notice stopped displaying on"
    And I should not see "Live · being shown"

  Scenario: A start date with no expiry runs from that date onwards
    # This used to read to an author as "from this date onwards" and behave as "never", because the
    # window check asked now < timeend with timeend zero. A zero bound is now unbounded on that
    # side, so the notice means what it says and the warning that papered over it is gone.
    Given the following site notices exist
      | title              | content            | enabled | timestart  | timeend |
      | Open ended notice  | <p>Body</p>        | 1       | 1600000000 | 0       |
    When I navigate to "Awareness > Manage" in site administration
    And I click on "Edit" "link" in the "Open ended notice" "table_row"
    Then I should see "Live · being shown"
    And I should not see "Published · nobody is seeing it"

  Scenario: A notice that is genuinely being shown still says Live
    # The control. Without it, a change that painted every notice as blocked would satisfy both
    # scenarios above while making the badge useless.
    Given the following site notices exist
      | title              | content            | enabled | timestart | timeend |
      | Perpetual notice   | <p>Body</p>        | 1       | 0         | 0       |
    When I navigate to "Awareness > Manage" in site administration
    And I click on "Edit" "link" in the "Perpetual notice" "table_row"
    Then I should see "Live · being shown"
    And I should not see "Published · nobody is seeing it"

  Scenario: The save-state line stops claiming a time once the form is touched
    Given the following site notices exist
      | title              | content            | enabled |
      | Saved notice       | <p>Body</p>        | 1       |
    When I navigate to "Awareness > Manage" in site administration
    And I click on "Edit" "link" in the "Saved notice" "table_row"
    Then I should see "Saved"
    And I should not see "Unsaved changes"
    When I set the field "Title" to "Touched"
    Then I should see "Unsaved changes"
