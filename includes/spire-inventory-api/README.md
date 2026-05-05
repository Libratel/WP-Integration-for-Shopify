# Spire Inventory API Plugin

## Description
The **Spire Inventory API Plugin** seamlessly integrates WooCommerce with Spire ERP, automating product synchronization and order processing. It provides an intuitive admin interface to manage settings, logs, and sync schedules, making it an essential tool for businesses leveraging Spire ERP in their e-commerce operations.

---

## Features

### Inventory Synchronization
- **Manual Sync**:  
  - Trigger product sync manually via the admin interface.
  - Creates new products in WooCommerce or updates existing ones with pricing, stock, and availability changes.
  - Syncs products from selected warehouses.

- **Scheduled Sync**:  
  - Schedule syncs for specific days, time ranges, and intervals.
  - Supports:
    - **Full Sync**: Updates all products.
    - **Incremental Sync**: Updates only modified products based on `onHandQty` and `availableQty`.
  - Maintains detailed logs of scheduled operations.

- **Warehouse Integration**:  
  - Dynamically fetch and select warehouses for synchronization.

### Order Posting
- Posts WooCommerce orders to Spire ERP when the status changes to "Processing" or "Completed".
- Includes customer details, order items, and billing/shipping information.
- Maps orders using the custom `Spire Customer No` field in WooCommerce user profiles.

### User-Friendly Admin Interface
Accessible via **Settings > Spire Inventory API**, the plugin offers:
- **General Tab**:
  - Configure API credentials.
  - Test API connection and load/select warehouses.
  - Trigger manual product sync.
- **Order Settings Tab**:
  - Review users missing `Spire Customer No`.
  - View and purge unsuccessful order POST attempts.
- **Sync Log Tab**:
  - View detailed logs of sync operations.
  - Clear logs when needed.
- **Schedule Sync Tab**:
  - Configure automatic sync schedules.
  - Logs all scheduled sync operations.

### Product & Customer Mapping
- **Product Mapping**:
  - Sync product data from Spire ERP to WooCommerce fields (`SKU`, `price`, `stock`, etc.).
  - Adds Spire-specific metadata (`onHandQty`, `availableQty`, `warehouse code`).
- **Customer Mapping**:
  - Adds `Spire Customer No` field to WooCommerce user profiles.
  - Ensures proper order mapping in Spire ERP.

### Error Handling & Logging
- **Detailed Logs**:
  - Sync logs include created, updated, and skipped products with reasons for skipped items.
  - Separate logs for manual sync, scheduled sync, and order POST operations.
- **Error Messaging**:
  - Displays clear messages for connection failures or invalid configurations.

---

## Technical Details

### Data Synchronization
- **API Endpoints**:
  - Inventory Sync: `/companies/{company}/inventory/items/`
  - Order Posting: `/companies/{company}/sales/orders/`
- **Sync Modes**:
  - **Full Sync**: Updates all product records.
  - **Incremental Sync**: Updates only modified products based on `onHandQty` and `availableQty`.
- **Metadata**:
  - `_spire_item_id`
  - `_spire_item_onHandQty`
  - `_spire_item_availableQty`
  - `_spire_warehouse_code`

### Order Posting
- Posts WooCommerce orders as JSON to Spire ERP, including:
  - Customer information.
  - Item details.
  - Billing and shipping addresses.

### Plugin Architecture
- **Core Classes**:
  - `Spire_Plugin_Init`: Initializes plugin settings.
  - `Spire_Settings`: Manages API credentials.
  - `Spire_Admin_UI`: Builds admin interface and handles form submissions.
  - `Spire_Sync_Manager`: Handles sync operations.
  - `Spire_Orders_Manager`: Posts orders to Spire ERP.
  - `Spire_API_Client`: Manages API requests.
  - `Spire_Customer_Mapping`: Adds `Spire Customer No` to user profiles.
- **Database Usage**:
  - Stores logs and settings in WordPress options.

### Security
- Uses Basic Authentication for API communication.
- SSL verification is disabled for IP-based endpoints (can be improved for production).
- Nonces are used for form validation.

---

## Installation & Setup

1. **Install the Plugin**:
   - Upload the plugin via WordPress admin or FTP.
   - Activate the plugin.

2. **Configure Settings**:
   - Navigate to **Settings > Spire Inventory API**.
   - Enter API credentials and test the connection.
   - Load warehouses and select the ones for synchronization.

3. **Sync Products**:
   - Trigger manual sync or configure schedule sync settings.

4. **Post Orders**:
   - Ensure WooCommerce users have their `Spire Customer No` mapped.
   - Orders with "Processing" or "Completed" status will be posted to Spire ERP.

---

## Known Shortcomings
1. **Security**:
   - Basic Authentication is used with SSL verification disabled for some configurations.
   - Consider implementing OAuth or API tokens for enhanced security.

2. **Concurrency**:
   - No built-in queue for handling concurrent sync operations; high-volume syncs may face performance issues.

3. **Scalability**:
   - Optimized for thousands of SKUs, but may require additional optimization for millions of products.

4. **Retry Mechanism**:
   - Limited retries for failed API requests.

---

## Future Enhancements
1. **Generalization for Other ERPs**:
   - Abstract API interactions to support Sage 300, Microsoft Dynamics, Odoo, etc.
2. **Custom Pricing Integration**:
   - Add support for tiered pricing synced from Spire or defined in WooCommerce.
3. **Enhanced Scheduling**:
   - Allow overlapping sync tasks for different warehouses or categories.

---

## How It Works

1. **Manual Sync**:
   - Fetches product data from Spire ERP and updates WooCommerce products.

2. **Scheduled Sync**:
   - Automatically runs based on the schedule configured in the admin panel.

3. **Order Posting**:
   - Posts WooCommerce orders to Spire ERP, ensuring seamless communication between systems.

4. **Admin Interface**:
   - Provides detailed logs, user mapping, and configuration tools for streamlined management.

---

## Optimizations
- Incremental sync reduces API load and speeds up operations.
- Batching in chunks of 200 items ensures efficient data handling.
- Metadata management minimizes unnecessary database writes.

---

This plugin bridges WooCommerce and Spire ERP, offering robust, scalable, and efficient integration for modern e-commerce businesses.
