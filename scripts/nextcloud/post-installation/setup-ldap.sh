
# LDAP config
if $USE_LDAP = 'true'; then
    php /var/www/html/occ app:enable user_ldap
    php /var/www/html/occ ldap:create-empty-config

    php /var/www/html/occ ldap:set-config s01 ldapHost $LDAP_HOST
    php /var/www/html/occ ldap:set-config s01 ldapPort $LDAP_PORT
    php /var/www/html/occ ldap:set-config s01 ldapAgentName $LDAP_ADMIN
    php /var/www/html/occ ldap:set-config s01 ldapAgentPassword $LDAP_AGENT_PASSWORD
    php /var/www/html/occ ldap:set-config s01 hasMemberOfFilterSupport 1
    php /var/www/html/occ ldap:set-config s01 homeFolderNamingRule attr:cn
    php /var/www/html/occ ldap:set-config s01 ldapBase $LDAP_BASE
    php /var/www/html/occ ldap:set-config s01 ldapBaseUsers $LDAP_BASE
    php /var/www/html/occ ldap:set-config s01 ldapBaseGroups $LDAP_BASE
    php /var/www/html/occ ldap:set-config s01 ldapConfigurationActive 1
    php /var/www/html/occ ldap:set-config s01 ldapLoginFilter $LOGIN_FILTER
    php /var/www/html/occ ldap:set-config s01 ldapLoginFilterEmail 1
    php /var/www/html/occ ldap:set-config s01 ldapEmailAttribute mail
    php /var/www/html/occ ldap:set-config s01 ldapExpertUsernameAttr cn
    php /var/www/html/occ ldap:set-config s01 ldapGroupDisplayName description
    php /var/www/html/occ ldap:set-config s01 ldapGroupFilter '(&(|(objectclass=groupOfNames)))'
    php /var/www/html/occ ldap:set-config s01 ldapGroupGroups nextcloud
    php /var/www/html/occ ldap:set-config s01 ldapGroupFilterObjectclass groupOfNames
    php /var/www/html/occ ldap:set-config s01 ldapGroupMemberAssocAttr member
    php /var/www/html/occ ldap:set-config s01 ldapUserDisplayName displayname
    php /var/www/html/occ ldap:set-config s01 ldapUserFilter $USER_FILTER
    php /var/www/html/occ ldap:set-config s01 ldapUserFilterGroups nextcloud
    php /var/www/html/occ ldap:set-config s01 ldapUserFilterObjectclass inetOrgPerson
    php /var/www/html/occ ldap:set-config s01 ldapUuidGroupAttribute auto
    php /var/www/html/occ ldap:set-config s01 ldapUuidUserAttribute auto
    php /var/www/html/occ ldap:set-config s01 turnOnPasswordChange 1
    php /var/www/html/occ ldap:set-config s01 useMemberOfToDetectMembership 1
    php /var/www/html/occ ldap:set-config s01 ldapQuotaAttribute nextcloudQuota
    php /var/www/html/occ ldap:set-config s01 ldapQuotaDefault 1GB
fi
