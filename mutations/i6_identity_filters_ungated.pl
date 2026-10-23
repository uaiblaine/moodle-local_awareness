s/\$entity->get_identity_filters\(\$context\)/array_map([\$entity, 'get_identity_filter'], explode(',', get_config('core', 'showuseridentity')))/;
