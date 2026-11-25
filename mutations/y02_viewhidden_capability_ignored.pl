s/return \$course->visible \|\| has_capability\('moodle\/course:viewhiddencourses', \$scope->context\(\)\);/return (bool) \$course->visible;/;
