s/\$entity->get_identity_columns\(\$context\)/array_map([\$entity, 'get_identity_column'], explode(',', get_config('core', 'showuseridentity')))/;
