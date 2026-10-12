import 'dart:async';
import 'dart:convert';

import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';

/// Catalog, contacts, and email rows on the same Drift database as the inbox.
class DomainLocalStore {
  DomainLocalStore(this._db);

  final InboxDatabase _db;
  final _changes = StreamController<void>.broadcast();

  static const listLimit = 500;

  Future<List<ChatCatalogItemModel>> products(
    int workspaceId, {
    String? search,
    String? itemKind,
    int limit = listLimit,
  }) async {
    final args = <Object?>[workspaceId];
    final where = StringBuffer('workspace_id = ? AND deleted_at IS NULL');
    if (itemKind == 'digital') {
      where.write(" AND (item_kind = 'digital' OR tangibility = 'digital')");
    } else if (itemKind != null && itemKind.isNotEmpty) {
      where.write(' AND item_kind = ?');
      args.add(itemKind);
    }
    final q = search?.trim().toLowerCase() ?? '';
    if (q.isNotEmpty) {
      where.write(
        " AND (lower(name) LIKE ? ESCAPE '\\' OR lower(COALESCE(sku, '')) LIKE ? ESCAPE '\\')",
      );
      final like = _like(q);
      args.add(like);
      args.add(like);
    }
    args.add(limit);
    final rows = await _db.select(
      'SELECT * FROM local_catalog_items WHERE $where ORDER BY name COLLATE NOCASE LIMIT ?',
      args,
    );
    final items = <ChatCatalogItemModel>[];
    for (final row in rows) {
      items.add(await _productFromRow(workspaceId, row, withVariants: false));
    }
    return items;
  }

  Stream<List<ChatCatalogItemModel>> watchProducts(
    int workspaceId, {
    String? search,
    String? itemKind,
  }) {
    return _query(
      () => products(workspaceId, search: search, itemKind: itemKind),
    );
  }

  Future<ChatCatalogItemModel?> product(int workspaceId, int serverId) async {
    final rows = await _db.select(
      'SELECT * FROM local_catalog_items WHERE workspace_id = ? AND server_id = ? AND deleted_at IS NULL LIMIT 1',
      [workspaceId, serverId],
    );
    if (rows.isEmpty) return null;
    return _productFromRow(workspaceId, rows.first, withVariants: true);
  }

  Future<void> upsertProducts(
    int workspaceId,
    List<ChatCatalogItemModel> items,
  ) async {
    final now = DateTime.now().millisecondsSinceEpoch;
    for (final item in items) {
      if (item.id <= 0) continue;
      await _db.custom(
        '''
        INSERT INTO local_catalog_items (
          workspace_id, server_id, name, slug, description, sku, item_kind, tangibility,
          price_text, worker_min_text, worker_max_text, currency, stock, inventory_policy,
          is_active, image_url, category_server_id, updated_at, cached_at, deleted_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
        ON CONFLICT(workspace_id, server_id) DO UPDATE SET
          name = excluded.name,
          slug = excluded.slug,
          description = excluded.description,
          sku = excluded.sku,
          item_kind = excluded.item_kind,
          tangibility = excluded.tangibility,
          price_text = excluded.price_text,
          worker_min_text = excluded.worker_min_text,
          worker_max_text = excluded.worker_max_text,
          currency = excluded.currency,
          stock = excluded.stock,
          inventory_policy = excluded.inventory_policy,
          is_active = excluded.is_active,
          image_url = excluded.image_url,
          category_server_id = excluded.category_server_id,
          updated_at = excluded.updated_at,
          cached_at = excluded.cached_at,
          deleted_at = NULL
        ''',
        [
          workspaceId,
          item.id,
          item.name,
          item.slug,
          item.description,
          item.sku,
          item.itemKind,
          item.tangibility,
          item.price.toString(),
          item.workerMinimumPrice?.toString(),
          item.workerMaximumPrice?.toString(),
          item.currency,
          item.stock,
          item.inventoryPolicy,
          item.isActive ? 1 : 0,
          item.imageUrl,
          item.categoryId ?? item.category?.id,
          now,
          now,
        ],
      );
      if (item.variants.isNotEmpty) {
        await _db.custom(
          'DELETE FROM local_catalog_variants WHERE workspace_id = ? AND product_server_id = ?',
          [workspaceId, item.id],
        );
        for (final variant in item.variants) {
          if (variant.id <= 0) continue;
          await _db.custom(
            '''
            INSERT INTO local_catalog_variants (
              workspace_id, server_id, product_server_id, name, sku, price_text, currency,
              stock, inventory_policy, is_active, is_visible, status, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ''',
            [
              workspaceId,
              variant.id,
              item.id,
              variant.name,
              variant.sku,
              variant.price?.toString(),
              variant.currency,
              variant.stock,
              variant.inventoryPolicy,
              variant.isActive ? 1 : 0,
              variant.isVisible ? 1 : 0,
              variant.status,
              variant.sortOrder,
            ],
          );
        }
      }
    }
    _changed();
  }

  Future<void> deleteProduct(int workspaceId, int serverId) async {
    final now = DateTime.now().millisecondsSinceEpoch;
    await _db.update(
      'UPDATE local_catalog_items SET deleted_at = ? WHERE workspace_id = ? AND server_id = ?',
      [now, workspaceId, serverId],
    );
    _changed();
  }

  Future<void> setProductActive(
    int workspaceId,
    int serverId, {
    required bool active,
  }) async {
    await _db.update(
      'UPDATE local_catalog_items SET is_active = ? WHERE workspace_id = ? AND server_id = ?',
      [active ? 1 : 0, workspaceId, serverId],
    );
    _changed();
  }

  Future<List<ChatCatalogCategoryModel>> categories(int workspaceId) async {
    final rows = await _db.select(
      'SELECT * FROM local_catalog_categories WHERE workspace_id = ? ORDER BY sort_order, name LIMIT ?',
      [workspaceId, listLimit],
    );
    return rows.map(_categoryFromRow).toList();
  }

  Stream<List<ChatCatalogCategoryModel>> watchCategories(int workspaceId) {
    return _query(() => categories(workspaceId));
  }

  Future<void> upsertCategories(
    int workspaceId,
    List<ChatCatalogCategoryModel> items,
  ) async {
    for (final item in items) {
      if (item.id <= 0) continue;
      await _db.custom(
        '''
        INSERT INTO local_catalog_categories (
          workspace_id, server_id, name, slug, description, parent_server_id, status,
          is_active, is_visible, is_featured, sort_order
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(workspace_id, server_id) DO UPDATE SET
          name = excluded.name,
          slug = excluded.slug,
          description = excluded.description,
          parent_server_id = excluded.parent_server_id,
          status = excluded.status,
          is_active = excluded.is_active,
          is_visible = excluded.is_visible,
          is_featured = excluded.is_featured,
          sort_order = excluded.sort_order
        ''',
        [
          workspaceId,
          item.id,
          item.name,
          item.slug,
          item.description,
          item.parentId,
          item.status,
          item.isActive ? 1 : 0,
          item.isVisible ? 1 : 0,
          item.isFeatured ? 1 : 0,
          item.sortOrder,
        ],
      );
    }
    _changed();
  }

  Future<void> deleteCategory(int workspaceId, int serverId) async {
    await _db.delete(
      'DELETE FROM local_catalog_categories WHERE workspace_id = ? AND server_id = ?',
      [workspaceId, serverId],
    );
    _changed();
  }

  Future<void> upsertDigitalPools(
    int workspaceId,
    List<Map<String, dynamic>> pools,
  ) async {
    for (final pool in pools) {
      final id = (pool['id'] as num?)?.toInt();
      if (id == null || id <= 0) continue;
      await _db.custom(
        '''
        INSERT INTO local_digital_pools (workspace_id, server_id, payload_json)
        VALUES (?, ?, ?)
        ON CONFLICT(workspace_id, server_id) DO UPDATE SET payload_json = excluded.payload_json
        ''',
        [workspaceId, id, jsonEncode(_safeMap(pool))],
      );
    }
    _changed();
  }

  Future<List<Map<String, dynamic>>> digitalPools(int workspaceId) async {
    final rows = await _db.select(
      'SELECT payload_json FROM local_digital_pools WHERE workspace_id = ? LIMIT ?',
      [workspaceId, listLimit],
    );
    return [
      for (final row in rows)
        if (jsonDecode(row['payload_json']?.toString() ?? '{}') is Map)
          Map<String, dynamic>.from(
            jsonDecode(row['payload_json']!.toString()) as Map,
          ),
    ];
  }

  Future<void> upsertDigitalUnits(
    int workspaceId,
    int poolId,
    List<Map<String, dynamic>> units,
  ) async {
    for (final unit in units) {
      final id = (unit['id'] as num?)?.toInt();
      if (id == null || id <= 0) continue;
      final metadata = unit['metadata'];
      final label = metadata is Map ? metadata['label']?.toString() : null;
      await _db.custom(
        '''
        INSERT INTO local_digital_units (
          workspace_id, server_id, pool_server_id, unit_type, status, expires_at, label
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(workspace_id, server_id) DO UPDATE SET
          pool_server_id = excluded.pool_server_id,
          unit_type = excluded.unit_type,
          status = excluded.status,
          expires_at = excluded.expires_at,
          label = excluded.label
        ''',
        [
          workspaceId,
          id,
          poolId,
          unit['unit_type']?.toString(),
          unit['status']?.toString(),
          unit['expires_at']?.toString(),
          label,
        ],
      );
    }
    _changed();
  }

  Future<List<EmailContactModel>> contacts(
    int workspaceId, {
    String? search,
    bool? favorite,
    int limit = listLimit,
  }) async {
    final args = <Object?>[workspaceId];
    final where = StringBuffer('workspace_id = ? AND deleted_at IS NULL');
    if (favorite == true) where.write(' AND is_favorite = 1');
    final q = search?.trim().toLowerCase() ?? '';
    if (q.isNotEmpty) {
      where.write(" AND search_text LIKE ? ESCAPE '\\'");
      args.add(_like(q));
    }
    args.add(limit);
    final rows = await _db.select(
      'SELECT * FROM local_contacts WHERE $where ORDER BY name COLLATE NOCASE LIMIT ?',
      args,
    );
    return rows.map(_contactFromRow).toList();
  }

  Stream<List<EmailContactModel>> watchContacts(
    int workspaceId, {
    String? search,
    bool? favorite,
  }) {
    return _query(
      () => contacts(workspaceId, search: search, favorite: favorite),
    );
  }

  Future<void> upsertContacts(
    int workspaceId,
    List<EmailContactModel> items,
  ) async {
    for (final item in items) {
      if (item.id <= 0) continue;
      final search =
          '${item.name} ${item.email} ${item.phone ?? ''} ${item.company ?? ''}'
              .toLowerCase();
      await _db.custom(
        '''
        INSERT INTO local_contacts (
          workspace_id, server_id, name, email, normalized_email, phone, company, job_title,
          notes, is_favorite, avatar_url, created_at, updated_at, search_text, deleted_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
        ON CONFLICT(workspace_id, server_id) DO UPDATE SET
          name = excluded.name,
          email = excluded.email,
          normalized_email = excluded.normalized_email,
          phone = excluded.phone,
          company = excluded.company,
          job_title = excluded.job_title,
          notes = excluded.notes,
          is_favorite = excluded.is_favorite,
          avatar_url = excluded.avatar_url,
          created_at = excluded.created_at,
          updated_at = excluded.updated_at,
          search_text = excluded.search_text,
          deleted_at = NULL
        ''',
        [
          workspaceId,
          item.id,
          item.name,
          item.email,
          item.normalizedEmail,
          item.phone,
          item.company,
          item.jobTitle,
          item.notes,
          item.isFavorite ? 1 : 0,
          item.avatarUrl,
          item.createdAt?.millisecondsSinceEpoch,
          item.updatedAt?.millisecondsSinceEpoch,
          search,
        ],
      );
    }
    _changed();
  }

  Future<void> deleteContact(int workspaceId, int serverId) async {
    final now = DateTime.now().millisecondsSinceEpoch;
    await _db.update(
      'UPDATE local_contacts SET deleted_at = ? WHERE workspace_id = ? AND server_id = ?',
      [now, workspaceId, serverId],
    );
    await _db.delete(
      'DELETE FROM local_contact_group_members WHERE workspace_id = ? AND contact_server_id = ?',
      [workspaceId, serverId],
    );
    _changed();
  }

  Future<void> upsertGroups(
    int workspaceId,
    List<ContactGroupModel> groups,
  ) async {
    for (final group in groups) {
      if (group.id <= 0) continue;
      await _db.custom(
        '''
        INSERT INTO local_contact_groups (workspace_id, server_id, name, description, contacts_count)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(workspace_id, server_id) DO UPDATE SET
          name = excluded.name,
          description = excluded.description,
          contacts_count = excluded.contacts_count
        ''',
        [
          workspaceId,
          group.id,
          group.name,
          group.description,
          group.contactsCount,
        ],
      );
      if (group.contacts.isNotEmpty) {
        await upsertContacts(workspaceId, group.contacts);
        await _db.delete(
          'DELETE FROM local_contact_group_members WHERE workspace_id = ? AND group_server_id = ?',
          [workspaceId, group.id],
        );
        for (final contact in group.contacts) {
          if (contact.id <= 0) continue;
          await _db.custom(
            '''
            INSERT OR IGNORE INTO local_contact_group_members (
              workspace_id, group_server_id, contact_server_id
            ) VALUES (?, ?, ?)
            ''',
            [workspaceId, group.id, contact.id],
          );
        }
      }
    }
    _changed();
  }

  Future<List<ContactGroupModel>> groups(int workspaceId) async {
    final rows = await _db.select(
      'SELECT * FROM local_contact_groups WHERE workspace_id = ? ORDER BY name LIMIT ?',
      [workspaceId, listLimit],
    );
    return [
      for (final row in rows)
        ContactGroupModel(
          id: (row['server_id'] as num).toInt(),
          name: row['name']?.toString() ?? '',
          description: row['description']?.toString(),
          contactsCount: (row['contacts_count'] as num?)?.toInt() ?? 0,
        ),
    ];
  }

  Future<void> deleteGroup(int workspaceId, int serverId) async {
    await _db.delete(
      'DELETE FROM local_contact_groups WHERE workspace_id = ? AND server_id = ?',
      [workspaceId, serverId],
    );
    await _db.delete(
      'DELETE FROM local_contact_group_members WHERE workspace_id = ? AND group_server_id = ?',
      [workspaceId, serverId],
    );
    _changed();
  }

  Future<List<EmailMessageModel>> emails(
    int workspaceId, {
    required String folder,
    String? search,
    int limit = 200,
  }) async {
    final args = <Object?>[workspaceId, folder];
    final where = StringBuffer('workspace_id = ? AND folder = ?');
    final q = search?.trim().toLowerCase() ?? '';
    if (q.isNotEmpty) {
      where.write(
        " AND (lower(COALESCE(subject, '')) LIKE ? ESCAPE '\\' "
        "OR lower(sender) LIKE ? ESCAPE '\\' "
        "OR lower(COALESCE(preview, '')) LIKE ? ESCAPE '\\')",
      );
      final like = _like(q);
      args.add(like);
      args.add(like);
      args.add(like);
    }
    args.add(limit);
    final rows = await _db.select(
      'SELECT * FROM local_emails WHERE $where ORDER BY created_at IS NULL, created_at DESC, server_id DESC LIMIT ?',
      args,
    );
    return rows.map(_emailFromRow).toList();
  }

  Stream<List<EmailMessageModel>> watchEmails(
    int workspaceId, {
    required String folder,
    String? search,
  }) {
    return _query(() => emails(workspaceId, folder: folder, search: search));
  }

  Future<void> clearEmailWorkspaceData(int workspaceId) async {
    await _db.delete('DELETE FROM local_emails WHERE workspace_id = ?', [
      workspaceId,
    ]);
    await _db.delete('DELETE FROM local_email_drafts WHERE workspace_id = ?', [
      workspaceId,
    ]);
    await _db.delete(
      'DELETE FROM local_email_campaigns WHERE workspace_id = ?',
      [workspaceId],
    );
    await _db.delete('DELETE FROM local_contacts WHERE workspace_id = ?', [
      workspaceId,
    ]);
    await _db.delete(
      'DELETE FROM local_contact_groups WHERE workspace_id = ?',
      [workspaceId],
    );
    await _db.delete(
      'DELETE FROM local_contact_group_members WHERE workspace_id = ?',
      [workspaceId],
    );
    _changed();
  }

  Future<EmailMessageModel?> email(int workspaceId, int serverId) async {
    final rows = await _db.select(
      'SELECT * FROM local_emails WHERE workspace_id = ? AND server_id = ? LIMIT 1',
      [workspaceId, serverId],
    );
    if (rows.isEmpty) return null;
    return _emailFromRow(rows.first);
  }

  Future<void> upsertEmails(
    int workspaceId,
    String folder,
    List<EmailMessageModel> items, {
    bool keepBody = false,
  }) async {
    for (final item in items) {
      if (item.id <= 0) continue;
      final existing = await email(workspaceId, item.id);
      final body =
          item.body ?? (keepBody ? existing?.body : null) ?? existing?.body;
      await _db.custom(
        '''
        INSERT INTO local_emails (
          workspace_id, server_id, folder, sender, recipient, subject, type, preview, body,
          is_read, is_starred, created_at, email_account_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(workspace_id, server_id) DO UPDATE SET
          folder = excluded.folder,
          sender = excluded.sender,
          recipient = excluded.recipient,
          subject = excluded.subject,
          type = excluded.type,
          preview = excluded.preview,
          body = COALESCE(excluded.body, local_emails.body),
          is_read = excluded.is_read,
          is_starred = excluded.is_starred,
          created_at = excluded.created_at,
          email_account_id = excluded.email_account_id
        ''',
        [
          workspaceId,
          item.id,
          folder,
          item.sender,
          item.recipient,
          item.subject,
          item.type,
          item.preview,
          body,
          item.isRead ? 1 : 0,
          item.isStarred ? 1 : 0,
          item.createdAt?.millisecondsSinceEpoch,
          item.emailAccountId,
        ],
      );
    }
    _changed();
  }

  Future<void> setEmailFlags(
    int workspaceId,
    int serverId, {
    bool? isRead,
    bool? isStarred,
  }) async {
    if (isRead != null) {
      await _db.update(
        'UPDATE local_emails SET is_read = ? WHERE workspace_id = ? AND server_id = ?',
        [isRead ? 1 : 0, workspaceId, serverId],
      );
    }
    if (isStarred != null) {
      await _db.update(
        'UPDATE local_emails SET is_starred = ? WHERE workspace_id = ? AND server_id = ?',
        [isStarred ? 1 : 0, workspaceId, serverId],
      );
    }
    _changed();
  }

  Future<void> saveComposeDraft({
    required int workspaceId,
    required String recipients,
    required String subject,
    required String body,
  }) async {
    final now = DateTime.now().millisecondsSinceEpoch;
    await _db.custom(
      '''
      INSERT INTO local_email_drafts (workspace_id, recipients, subject, body, updated_at)
      VALUES (?, ?, ?, ?, ?)
      ON CONFLICT(workspace_id) DO UPDATE SET
        recipients = excluded.recipients,
        subject = excluded.subject,
        body = excluded.body,
        updated_at = excluded.updated_at
      ''',
      [workspaceId, recipients, subject, body, now],
    );
    _changed();
  }

  Future<({String recipients, String subject, String body})?> composeDraft(
    int workspaceId,
  ) async {
    final rows = await _db.select(
      'SELECT * FROM local_email_drafts WHERE workspace_id = ? LIMIT 1',
      [workspaceId],
    );
    if (rows.isEmpty) return null;
    final row = rows.first;
    return (
      recipients: row['recipients']?.toString() ?? '',
      subject: row['subject']?.toString() ?? '',
      body: row['body']?.toString() ?? '',
    );
  }

  Future<void> deleteComposeDraft(int workspaceId) async {
    await _db.delete('DELETE FROM local_email_drafts WHERE workspace_id = ?', [
      workspaceId,
    ]);
    _changed();
  }

  Future<void> upsertCampaign(
    int workspaceId,
    EmailCampaignModel campaign,
  ) async {
    if (campaign.id <= 0) return;
    await _db.custom(
      '''
      INSERT INTO local_email_campaigns (
        workspace_id, server_id, email_account_id, subject, status, recipient_count,
        sent_count, failed_count, created_at, account_name, account_email
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON CONFLICT(workspace_id, server_id) DO UPDATE SET
        email_account_id = excluded.email_account_id,
        subject = excluded.subject,
        status = excluded.status,
        recipient_count = excluded.recipient_count,
        sent_count = excluded.sent_count,
        failed_count = excluded.failed_count,
        created_at = excluded.created_at,
        account_name = excluded.account_name,
        account_email = excluded.account_email
      ''',
      [
        workspaceId,
        campaign.id,
        campaign.emailAccountId,
        campaign.subject,
        campaign.status,
        campaign.recipientCount,
        campaign.sentCount,
        campaign.failedCount,
        campaign.createdAt?.millisecondsSinceEpoch,
        campaign.accountName,
        campaign.accountEmail,
      ],
    );
    _changed();
  }

  Future<EmailCampaignModel?> campaign(int workspaceId, int serverId) async {
    final rows = await _db.select(
      'SELECT * FROM local_email_campaigns WHERE workspace_id = ? AND server_id = ? LIMIT 1',
      [workspaceId, serverId],
    );
    if (rows.isEmpty) return null;
    final row = rows.first;
    return EmailCampaignModel(
      id: (row['server_id'] as num).toInt(),
      emailAccountId: (row['email_account_id'] as num?)?.toInt(),
      subject: row['subject']?.toString(),
      status: row['status']?.toString() ?? 'queued',
      recipientCount: (row['recipient_count'] as num?)?.toInt() ?? 0,
      sentCount: (row['sent_count'] as num?)?.toInt() ?? 0,
      failedCount: (row['failed_count'] as num?)?.toInt() ?? 0,
      createdAt: _time(row['created_at']),
      accountName: row['account_name']?.toString(),
      accountEmail: row['account_email']?.toString(),
    );
  }

  Future<ChatCatalogItemModel> _productFromRow(
    int workspaceId,
    Map<String, Object?> row, {
    required bool withVariants,
  }) async {
    final serverId = (row['server_id'] as num).toInt();
    final variants = <ChatCatalogVariantModel>[];
    if (withVariants) {
      final variantRows = await _db.select(
        'SELECT * FROM local_catalog_variants WHERE workspace_id = ? AND product_server_id = ? ORDER BY sort_order',
        [workspaceId, serverId],
      );
      for (final variant in variantRows) {
        variants.add(
          ChatCatalogVariantModel(
            id: (variant['server_id'] as num).toInt(),
            name: variant['name']?.toString() ?? '',
            sku: variant['sku']?.toString(),
            price: num.tryParse(variant['price_text']?.toString() ?? ''),
            currency: variant['currency']?.toString(),
            stock: (variant['stock'] as num?)?.toInt(),
            inventoryPolicy: variant['inventory_policy']?.toString(),
            isActive: variant['is_active'] == 1,
            isVisible: variant['is_visible'] == 1,
            status: variant['status']?.toString(),
            sortOrder: (variant['sort_order'] as num?)?.toInt() ?? 0,
          ),
        );
      }
    }
    final categoryId = (row['category_server_id'] as num?)?.toInt();
    return ChatCatalogItemModel(
      id: serverId,
      name: row['name']?.toString() ?? '',
      slug: row['slug']?.toString(),
      description: row['description']?.toString(),
      sku: row['sku']?.toString(),
      itemKind: row['item_kind']?.toString() ?? 'product',
      tangibility: row['tangibility']?.toString() ?? 'tangible',
      price: num.tryParse(row['price_text']?.toString() ?? '') ?? 0,
      workerMinimumPrice: num.tryParse(
        row['worker_min_text']?.toString() ?? '',
      ),
      workerMaximumPrice: num.tryParse(
        row['worker_max_text']?.toString() ?? '',
      ),
      currency: row['currency']?.toString() ?? 'SAR',
      stock: (row['stock'] as num?)?.toInt(),
      inventoryPolicy: row['inventory_policy']?.toString(),
      isActive: row['is_active'] == 1,
      imageUrl: row['image_url']?.toString(),
      categoryId: categoryId,
      variants: variants,
    );
  }

  ChatCatalogCategoryModel _categoryFromRow(Map<String, Object?> row) {
    return ChatCatalogCategoryModel(
      id: (row['server_id'] as num).toInt(),
      name: row['name']?.toString() ?? '',
      slug: row['slug']?.toString(),
      description: row['description']?.toString(),
      parentId: (row['parent_server_id'] as num?)?.toInt(),
      status: row['status']?.toString(),
      isActive: row['is_active'] == 1,
      isVisible: row['is_visible'] == 1,
      isFeatured: row['is_featured'] == 1,
      sortOrder: (row['sort_order'] as num?)?.toInt() ?? 0,
    );
  }

  EmailContactModel _contactFromRow(Map<String, Object?> row) {
    return EmailContactModel(
      id: (row['server_id'] as num).toInt(),
      name: row['name']?.toString() ?? '',
      email: row['email']?.toString() ?? '',
      normalizedEmail: row['normalized_email']?.toString(),
      phone: row['phone']?.toString(),
      company: row['company']?.toString(),
      jobTitle: row['job_title']?.toString(),
      notes: row['notes']?.toString(),
      isFavorite: row['is_favorite'] == 1,
      avatarUrl: row['avatar_url']?.toString(),
      createdAt: _time(row['created_at']),
      updatedAt: _time(row['updated_at']),
    );
  }

  EmailMessageModel _emailFromRow(Map<String, Object?> row) {
    return EmailMessageModel(
      id: (row['server_id'] as num).toInt(),
      sender: row['sender']?.toString() ?? '',
      recipient: row['recipient']?.toString() ?? '',
      subject: row['subject']?.toString(),
      type: row['type']?.toString(),
      preview: row['preview']?.toString(),
      body: row['body']?.toString(),
      isRead: row['is_read'] != 0,
      isStarred: row['is_starred'] == 1,
      createdAt: _time(row['created_at']),
      emailAccountId: (row['email_account_id'] as num?)?.toInt(),
    );
  }

  Map<String, dynamic> _safeMap(Map<String, dynamic> raw) {
    const blocked = {
      'secret',
      'secrets',
      'code',
      'codes',
      'token',
      'credential',
      'credentials',
      'license',
      'pin',
    };
    final safe = <String, dynamic>{};
    for (final entry in raw.entries) {
      if (blocked.contains(entry.key.toLowerCase())) continue;
      final value = entry.value;
      if (value is Map) {
        safe[entry.key] = _safeMap(Map<String, dynamic>.from(value));
      } else {
        safe[entry.key] = value;
      }
    }
    return safe;
  }

  Stream<T> _query<T>(Future<T> Function() read) {
    return Stream<T>.multi((listener) {
      var alive = true;
      Future<void> emit() async {
        try {
          final value = await read();
          if (alive) listener.add(value);
        } catch (error, stack) {
          if (alive) listener.addError(error, stack);
        }
      }

      final subscription = _changes.stream.listen((_) => unawaited(emit()));
      unawaited(emit());
      listener.onCancel = () {
        alive = false;
        unawaited(subscription.cancel());
      };
    });
  }

  void _changed() {
    if (!_changes.isClosed) _changes.add(null);
  }

  static String _like(String query) {
    final escaped = query
        .replaceAll(r'\', r'\\')
        .replaceAll('%', r'\%')
        .replaceAll('_', r'\_');
    return '%$escaped%';
  }

  static DateTime? _time(Object? value) {
    if (value is num) return DateTime.fromMillisecondsSinceEpoch(value.toInt());
    return null;
  }
}
