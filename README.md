# Houzez XML Property Importer

**A Custom WordPress Plugin for Importing Properties from Any XML Feed**
Built specifically for the **Houzez Real Estate Theme**.

---

## 📌 Overview

**Houzez XML Property Importer** is a custom WordPress plugin that allows you to import real estate properties from **any XML URL/feed** directly into the Houzez theme’s property post type.

This plugin was developed for a client and is fully functional, stable, and optimized for Houzez. While it is designed to work out-of-the-box with the Houzez theme, developers can easily modify the post type to make it compatible with **any WordPress theme**.

---

## ✨ Features

* **Import properties from any XML link**
  Just provide the XML feed URL—no format restrictions as long as the required fields exist.

* **Full compatibility with Houzez theme**
  Automatically maps imported data to Houzez fields and meta keys.

* **Custom field mapping**
  Supports property title, description, price, location, images, coordinates, and more.

* **Automatic property creation**
  XML items are converted into WordPress property posts (Houzez `property` post type).

* **Image downloading & attachment**
  The plugin fetches and imports all property images from the XML feed.

* **Overwrite or skip duplicates** (if enabled)
  Avoid duplicate posts by checking the property ID or unique field.

* **Developer-friendly structure**
  Change the post type and meta keys easily to use with any other theme.

---

## 🎯 Why This Plugin Exists

Most XML-to-WordPress importers are overly complex or require expensive paid subscriptions.

This custom plugin:

* Simplifies the import process
* Is lightweight and theme-ready
* Makes XML data usable for real estate professionals using Houzez
* Gives developers full control with clean and extendable code

---

## 🛠 Installation

1. Upload the plugin to:

   ```
   /wp-content/plugins/
   ```
2. Activate via **WordPress → Plugins**.
3. Go to the plugin settings page (if provided) or update the XML URL inside the configuration.
4. Run the importer to fetch properties.

---

## ⚙ How It Works

1. The plugin fetches the XML data from the provided URL.
2. Each XML `<property>` entry is read and converted into a new post under the **Houzez property post type (`property`)**.
3. Meta fields and taxonomies are mapped automatically (price, address, features, coordinates, etc.).
4. All listed images are downloaded and attached to the property.
5. Existing properties can be updated or skipped based on your setup.

---

## 🔧 Developer Notes

While designed for Houzez, the plugin is built with flexibility in mind:

* **To use with another theme**, simply change the post type from:

  ```php
  'property'
  ```

  to any other custom post type (e.g., `listing`, `real_estate`, etc.).

* Meta field mappings can be adjusted inside the plugin file to match any theme’s structure.

---

## 📋 Requirements

* WordPress 5.0+
* PHP 7.4+
* Houzez Theme (for default functionality)
* XML feed with consistent property structure

---

## 🧩 Compatibility

✔ Houzez Theme (fully supported)
✔ Any real estate theme with minor adjustments
✔ Supports remote XML feeds
✔ Supports XML feeds with multiple images

---

## 📞 Support / Customization

If you need:

* Custom XML parsing rules
* Cron-based automatic imports
* Field mapping for a different theme
* Additional automation features

I can help extend or adapt the plugin to your needs.

---

## 📄 License

This is a custom-built plugin created for a client project.
You may modify and reuse the code as needed.

---

If you'd like, I can also add:
📸 Screenshot section
🔄 Changelog
🧪 Test instructions
⚙ Example XML structure

Just let me know!
