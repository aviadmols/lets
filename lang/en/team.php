<?php

// The shop's own team — see App\Filament\Resources\TeamMemberResource.
// Mirror in lang/he/team.php, key for key.
return [
    'nav' => 'Team',
    'title' => 'Team',
    'model' => 'Team member',
    'empty' => 'Only you so far. Add a colleague to give them their own login.',

    'form' => [
        'heading' => 'Team member',
        'intro' => 'They sign in with their own email and password, and see this store only.',
        'name' => 'Name',
        'email' => 'Email address',
        'password' => 'Password',
        'password_help' => 'At least 10 characters. Leave empty when editing to keep the current password.',
    ],

    'col' => [
        'name' => 'Name',
        'email' => 'Email',
        'added' => 'Added',
    ],

    'action' => [
        'add' => 'Add team member',
    ],

    'delete' => [
        'body' => ':email will no longer be able to sign in. This cannot be undone.',
    ],
];
