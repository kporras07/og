<?php

declare(strict_types = 1);

namespace Drupal\og\Entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\user\UserInterface;
use Drupal\og\Og;
use Drupal\og\OgMembershipInterface;
use Drupal\og\OgRoleInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * The membership entity that connects a group and a user.
 *
 * When dealing with non-user entities that are group content, that is content
 * that is associated with a group, we do it via an entity reference field that
 * has the default storage. The only information that we hold is that a group
 * content is referencing a group.
 *
 * However, when dealing with the user entity we recognize that we need to
 * special case it. It won't suffice to just hold the reference between the user
 * and the group content as it will be laking crucial information such as: the
 * state of the user's membership in the group (active, pending or blocked), the
 * time the membership was created, the user's OG role in the group, etc.
 *
 * For this meta data we have the fieldable OgMembership entity, that is always
 * connecting between a user and a group. There cannot be an OgMembership entity
 * connecting two non-user entities.
 *
 * Creating such a relation is done for example in the following way:
 *
 * @code
 *  $membership = Og::createMembership($entity, $user);
 *  $membership->save();
 * @endcode
 *
 * Notice how the relation of the user to the group also includes the OG
 * audience field name this association was done by. Like this we are able to
 * express different membership types such as the default membership that comes
 * out of the box, or a "premium membership" that can be for example expired
 * after a certain amount of time (the logic for the expired membership in the
 * example is out of the scope of OG core).
 *
 * Having this field separation is what allows having multiple OG audience
 * fields attached to the user, where each group they are associated with may be
 * a result of different membership types.
 *
 * @ContentEntityType(
 *   id = "og_membership",
 *   label = @Translation("OG membership"),
 *   bundle_label = @Translation("OG membership type"),
 *   module = "og",
 *   base_table = "og_membership",
 *   fieldable = TRUE,
 *   bundle_entity_type = "og_membership_type",
 *   entity_keys = {
 *     "uuid" = "uuid",
 *     "id" = "id",
 *     "bundle" = "type",
 *   },
 *   bundle_keys = {
 *     "bundle" = "type",
 *   },
 *   handlers = {
 *     "views_data" = "Drupal\og\OgMembershipViewsData",
 *     "form" = {
 *       "subscribe" = "Drupal\og\Form\GroupSubscribeForm",
 *       "unsubscribe" = "Drupal\og\Form\GroupUnsubscribeConfirmForm",
 *     },
 *   }
 * )
 */
class OgMembership extends ContentEntityBase implements OgMembershipInterface {

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime(int $timestamp): OgMembershipInterface {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwner() {
    assert(!empty($this->get('uid')->entity), new \LogicException(__METHOD__ . '() should only be called on loaded memberships, or on newly created memberships that already have the owner set.'));
    return $this->get('uid')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerId() {
    assert(!empty($this->get('uid')->entity), new \LogicException(__METHOD__ . '() should only be called on loaded memberships, or on newly created memberships that already have the owner set.'));
    return $this->get('uid')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwner(UserInterface $account) {
    $this->set('uid', $account->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwnerId($uid) {
    $this->set('uid', $uid);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setGroup(ContentEntityInterface $group): OgMembershipInterface {
    $this->set('entity_type', $group->getEntityTypeId());
    $this->set('entity_bundle', $group->bundle());
    $this->set('entity_id', $group->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupEntityType(): string {
    assert(!empty($this->get('entity_type')->value), new \LogicException(__METHOD__ . '() should only be called on loaded memberships, or on newly created memberships that already have the group type set.'));
    return $this->get('entity_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupBundle(): string {
    assert(!empty($this->get('entity_bundle')->value), new \LogicException(__METHOD__ . '() should only be called on loaded memberships, or on newly created memberships that already have the group bundle set.'));
    return $this->get('entity_bundle')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupId(): string {
    assert(!empty($this->get('entity_id')->value), new \LogicException(__METHOD__ . '() should only be called on loaded memberships, or on newly created memberships that already have the group ID set.'));
    return $this->get('entity_id')->value;
  }

  /**
   * Checks if a group has already been populated on the membership.
   *
   * The group is required for a membership, so it is always present if a
   * membership has been saved. This is intended for internal use to verify if
   * a group is present when methods are called on a membership that is possibly
   * still under construction.
   *
   * For performance reasons this avoids loading the full group entity just for
   * this purpose, and relies only on the fact that the data for the entity is
   * populated in the relevant fields. This should give us the same indication,
   * but with a lower performance cost, especially for users that are a member
   * of a large number of groups.
   *
   * @return bool
   *   Whether or not the group is already present.
   */
  protected function hasGroup(): bool {
    return !empty($this->get('entity_type')->value) && !empty($this->get('entity_bundle')->value) && !empty($this->get('entity_id')->value);
  }

  /**
   * {@inheritdoc}
   */
  public function getGroup(): ?ContentEntityInterface {
    assert(!empty($this->get('entity_type')->value) || !empty($this->get('entity_id')->value), new \LogicException(__METHOD__ . '() should only be called on loaded memberships, or on newly created memberships that already have the group set.'));
    $entity_type = $this->get('entity_type')->value;
    $entity_id = $this->get('entity_id')->value;

    return \Drupal::entityTypeManager()->getStorage($entity_type)->load($entity_id);
  }

  /**
   * {@inheritdoc}
   */
  public function setState(string $state): OgMembershipInterface {
    $this->set('state', $state);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getState(): string {
    return $this->get('state')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return $this->bundle();
  }

  /**
   * {@inheritdoc}
   */
  public function addRole(OgRoleInterface $role): OgMembershipInterface {
    $roles = $this->getRoles();
    $roles[] = $role;

    return $this->setRoles($roles);
  }

  /**
   * {@inheritdoc}
   */
  public function revokeRole(OgRoleInterface $role): OgMembershipInterface {
    return $this->revokeRoleById($role->id());
  }

  /**
   * {@inheritdoc}
   */
  public function revokeRoleById(string $role_id): OgMembershipInterface {
    $roles = $this->getRoles();

    foreach ($roles as $key => $existing_role) {
      if ($existing_role->id() == $role_id) {
        unset($roles[$key]);

        // We can stop iterating.
        break;
      }
    }

    return $this->setRoles($roles);
  }

  /**
   * {@inheritdoc}
   */
  public function getRoles(): array {
    $roles = [];

    // Add the member role. This is only possible if a group has been set on the
    // membership.
    if ($this->hasGroup()) {
      $roles = [
        OgRole::getRole($this->getGroupEntityType(), $this->getGroupBundle(), OgRoleInterface::AUTHENTICATED),
      ];
    }
    $roles = array_merge($roles, $this->get('roles')->referencedEntities());
    return $roles;
  }

  /**
   * {@inheritdoc}
   */
  public function setRoles(array $roles = []): OgMembershipInterface {
    $roles = array_filter($roles, function (OgRole $role) {
      return !($role->getName() == OgRoleInterface::AUTHENTICATED);
    });
    $role_ids = array_map(function (OgRole $role) {
      return $role->id();
    }, $roles);

    $this->set('roles', array_unique($role_ids));

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getRolesIds(): array {
    return array_map(function (OgRole $role) {
      return $role->id();
    }, $this->getRoles());
  }

  /**
   * {@inheritdoc}
   */
  public function isRoleValid(OgRoleInterface $role): bool {
    $group = $this->getGroup();

    // If there is no group yet then we cannot determine whether the role is
    // valid.
    if (!$group) {
      throw new \LogicException('Cannot determine whether a role is valid for a membership that doesn\'t have a group.');
    }

    // Non-member roles are never valid for any membership.
    if ($role->getName() == OgRoleInterface::ANONYMOUS) {
      return FALSE;
    }

    // If the entity type and bundle of the role doesn't match the group then
    // the role is intended for a different group type.
    elseif ($role->getGroupType() !== $group->getEntityTypeId() || $role->getGroupBundle() !== $group->bundle()) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasRole(string $role_id): bool {
    return in_array($role_id, $this->getRolesIds());
  }

  /**
   * {@inheritdoc}
   */
  public function hasPermission(string $permission): bool {
    // Blocked users do not have any permissions.
    if ($this->isBlocked()) {
      return FALSE;
    }

    return (bool) array_filter($this->getRoles(), function (OgRole $role) use ($permission) {
      return $role->hasPermission($permission);
    });
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = [];

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t("The group membership's unique ID."))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setDescription(t('The membership UUID.'))
      ->setReadOnly(TRUE);

    $fields['type'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Type'))
      ->setDescription(t('The bundle of the membership'))
      ->setSetting('target_type', 'og_membership_type');

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Member User ID'))
      ->setDescription(t('The user ID of the member.'))
      ->setSetting('target_type', 'user');

    $fields['entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Group entity type'))
      ->setDescription(t('The entity type of the group.'));

    $fields['entity_bundle'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Group bundle ID'))
      ->setDescription(t('The bundle ID of the group.'));

    $fields['entity_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Group entity ID'))
      ->setDescription(t('The entity ID of the group.'));

    $fields['state'] = BaseFieldDefinition::create('string')
      ->setLabel(t('State'))
      ->setDescription(t('The user membership state: active, pending, or blocked.'))
      ->setDefaultValue(OgMembershipInterface::STATE_ACTIVE);

    $fields['roles'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Roles'))
      ->setDescription(t('The OG roles related to an OG membership entity.'))
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'entity_reference_label',
        'weight' => 0,
      ])
      ->setSetting('target_type', 'og_role');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Create'))
      ->setDescription(t('The Unix timestamp when the membership was created.'));

    $fields['language'] = BaseFieldDefinition::create('language')
      ->setLabel(t('Language'))
      ->setDescription(t('The {languages}.language of this membership.'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    // Check the value directly rather than using the entity, if there is one.
    // This will watch actual empty values and '0'.
    if (!$this->get('uid')->target_id) {
      // Throw a generic logic exception as this will likely get caught in
      // \Drupal\Core\Entity\Sql\SqlContentEntityStorage::save and turned into
      // an EntityStorageException anyway.
      throw new \LogicException('OG membership can not be created for an empty or anonymous user.');
    }

    if (!$this->get('entity_id')->value) {
      // Group was not set.
      throw new \LogicException('Membership cannot be set for an empty or an unsaved group.');
    }

    if (!$group = $this->getGroup()) {
      throw new \LogicException('A group entity is required for creating a membership.');
    }

    $entity_type_id = $group->getEntityTypeId();
    $bundle = $group->bundle();
    if (!Og::isGroup($entity_type_id, $bundle)) {
      // Group is not valid.
      throw new \LogicException(sprintf('Entity type %s with ID %s is not an OG group.', $entity_type_id, $group->id()));
    }

    // Check if the roles are valid.
    foreach ($this->getRoles() as $role) {
      /** @var \Drupal\og\Entity\OgRole $role */
      // Make sure we don't save a membership for a non-member.
      if ($role->getName() == OgRoleInterface::ANONYMOUS) {
        throw new \LogicException('Cannot save an OgMembership with reference to a non-member role.');
      }
      // The regular membership is implied, we do not need to store it.
      elseif ($role->getName() == OgRoleInterface::AUTHENTICATED) {
        $this->revokeRole($role);
      }
      // The roles should apply to the group type.
      elseif (!$this->isRoleValid($role)) {
        throw new \LogicException(sprintf('The role with ID %s does not match the group type of the membership.', $role->id()));
      }
    }

    // Check for an existing membership.
    $query = \Drupal::entityQuery('og_membership');
    $query
      ->condition('uid', $this->get('uid')->target_id)
      ->condition('entity_id', $this->get('entity_id')->value)
      ->condition('entity_type', $this->get('entity_type')->value);

    if (!$this->isNew()) {
      // Filter out this membership.
      $query->condition('id', $this->id(), '<>');
    }

    $count = $query
      ->range(0, 1)
      ->count()
      ->execute();

    if ($count) {
      throw new \LogicException(sprintf('An OG membership already exists for uid %s in group of entity-type %s and ID: %s', $this->get('uid')->target_id, $entity_type_id, $this->getGroup()->id()));
    }

    parent::preSave($storage);
  }

  /**
   * {@inheritdoc}
   */
  public function save() {
    $result = parent::save();

    // Reset internal cache.
    Og::reset();
    \Drupal::service('og.access')->reset();

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  protected function invalidateTagsOnSave($update) {
    parent::invalidateTagsOnSave($update);

    // A membership was created or updated: invalidate the membership list cache
    // tags of its group. An updated membership may start to appear in a group's
    // membership listings because it now meets those listings' filtering
    // requirements. A newly created membership may start to appear in listings
    // because it did not exist before.
    $group = $this->getGroup();
    if (!empty($group)) {
      $tags = Cache::buildTags(OgMembershipInterface::GROUP_MEMBERSHIP_LIST_CACHE_TAG_PREFIX, $group->getCacheTagsToInvalidate());
      Cache::invalidateTags($tags);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected static function invalidateTagsOnDelete(EntityTypeInterface $entity_type, array $entities) {
    parent::invalidateTagsOnDelete($entity_type, $entities);

    // A membership was deleted: invalidate the list cache tags of its group
    // membership lists, so that any lists that contain the membership will be
    // recalculated.
    $tags = [];
    foreach ($entities as $entity) {
      if ($group = $entity->getGroup()) {
        $tags = Cache::mergeTags(Cache::buildTags(OgMembershipInterface::GROUP_MEMBERSHIP_LIST_CACHE_TAG_PREFIX, $group->getCacheTagsToInvalidate()), $tags);
      }
    }
    Cache::invalidateTags($tags);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(array $values = []) {
    // Use the default membership type by default.
    $values += ['type' => OgMembershipInterface::TYPE_DEFAULT];
    return parent::create($values);
  }

  /**
   * {@inheritdoc}
   */
  public function isActive(): bool {
    return $this->getState() === OgMembershipInterface::STATE_ACTIVE;
  }

  /**
   * {@inheritdoc}
   */
  public function isPending(): bool {
    return $this->getState() === OgMembershipInterface::STATE_PENDING;
  }

  /**
   * {@inheritdoc}
   */
  public function isBlocked(): bool {
    return $this->getState() === OgMembershipInterface::STATE_BLOCKED;
  }

  /**
   * {@inheritdoc}
   */
  public function isOwner(): bool {
    $group = $this->getGroup();
    return $group instanceof EntityOwnerInterface && $group->getOwnerId() == $this->getOwnerId();
  }

}
