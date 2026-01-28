# LOL AI Recommender WordPress Plugin

A production-quality WordPress plugin that crawls Dutchie-hosted dispensary menus, stores product information locally, and provides an interactive AI chatbot for product recommendations.

## Features

- **Sitemap Crawling**: Automatically crawls Dutchie-hosted menu sitemaps to discover products
- **Product Storage**: Stores product information locally in WordPress using custom post types
- **AI Chatbot**: Interactive chatbot powered by OpenAI that asks clarifying questions and recommends products
- **Smart Recommendations**: Product scoring and ranking based on user preferences
- **Polite Crawling**: Respects rate limits, robots.txt, and uses caching to minimize server load
- **Dutchie Integration**: Seamlessly redirects users to Dutchie for viewing and purchasing products

## Installation

### Step 1: Upload Plugin

1. Download or clone this plugin
2. Upload the `lol-ai-recommender` folder to `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress

### Step 2: Configure OpenAI API Key

**Preferred Method (SiteGround/Server Environment Variable):**

1. Set the `OPENAI_API_KEY` environment variable on your server
2. For SiteGround, you can set this in your hosting control panel under "Environment Variables" or via `.htaccess`:
   ```apache
   SetEnv OPENAI_API_KEY "your-api-key-here"
   ```

**Alternative Method (wp-config.php):**

If you cannot set environment variables, you can define it in `wp-config.php`:

```php
define('OPENAI_API_KEY', 'your-api-key-here');
```

**Fallback Method (Plugin Settings):**

If neither of the above methods work, you can enter the API key directly in the plugin settings page (less secure, but functional).

### Step 3: Configure Dutchie URLs

1. Go to **LOL Products → Settings** in your WordPress admin
2. Enter the following:
   - **Dutchie Menu Base URL**: The base URL of your Dutchie-hosted menu (e.g., `https://yourdomain.com/menu`)
   - **Dutchie Sitemap URL**: The XML sitemap URL (e.g., `https://yourdomain.com/sitemap.xml`)
     - If not provided, the plugin will attempt to build it from the menu base URL

### Step 4: Configure Sync Settings

In the same settings page, configure:

- **Sync Frequency**: How often to automatically sync products (Hourly, Twice Daily, Daily)
- **Crawl Rate Limit**: Maximum requests per minute (default: 30)
- **Max Products Per Sync**: Maximum products to process per sync run (default: 100)
- **Chat Rate Limit**: Maximum chat requests per minute per IP (default: 10)

### Step 5: Run Initial Sync

1. In the plugin settings page, click **"Run Sync Now"** to perform the first manual sync
2. Wait for the sync to complete (this may take several minutes depending on the number of products)
3. Check the sync status section for any errors

### Step 6: Add Chatbot to a Page

1. Create a new page or edit an existing page
2. Add the shortcode: `[lol_ai_recommender]`
3. Optionally customize the title: `[lol_ai_recommender title="Custom Title"]`
4. Publish the page

## Usage

### For End Users

1. Visit the page with the chatbot shortcode
2. Start a conversation by asking about products (e.g., "I'm looking for something relaxing")
3. Answer the chatbot's clarifying questions
4. Receive personalized product recommendations
5. Click "View/Buy on Dutchie" to be redirected to the product page on Dutchie

### For Administrators

- **View Products**: Go to **LOL Products** in the admin menu to see all synced products
- **Manual Sync**: Use the "Run Sync Now" button in settings to trigger a manual sync
- **Monitor Status**: Check the sync status section for last sync time and any errors
- **Manage Products**: Edit products, categories, brands, and effects like regular WordPress posts

## How It Works

### Product Crawling

1. The plugin fetches the XML sitemap from the configured URL
2. Extracts product URLs from the sitemap
3. Fetches each product page with polite rate limiting
4. Parses product data using:
   - JSON-LD structured data (Product schema)
   - OpenGraph meta tags
   - Embedded JSON blobs (for React/SPA sites)
5. Stores products locally in WordPress

### AI Recommendations

1. User starts a conversation with the chatbot
2. OpenAI analyzes the conversation to extract:
   - Product category preferences
   - Desired effects
   - Budget constraints
   - Brand preferences
   - Must-have features
   - Things to avoid
3. The recommendation engine scores products based on these filters
4. Top-scoring products are returned with explanations

### Product Scoring

Products are scored based on:
- Category match (high weight)
- Brand match (high weight)
- Effects match (medium weight)
- Keyword matches in title/description
- Price within budget
- In-stock status
- Must-have features
- Avoid keywords (penalty)

## File Structure

```
lol-ai-recommender/
├── lol-ai-recommender.php    # Main plugin file
├── includes/
│   ├── cpt.php               # Custom post type and taxonomies
│   ├── admin.php             # Admin settings page
│   ├── crawler.php           # Sitemap crawler
│   ├── parser.php            # Product data parser
│   ├── sync.php              # Sync manager
│   ├── openai.php            # OpenAI integration
│   ├── recommend.php         # Recommendation engine
│   └── rest.php              # REST API endpoints
├── assets/
│   ├── js/
│   │   ├── chat.js           # Frontend chat UI
│   │   └── admin.js          # Admin JavaScript
│   └── css/
│       └── chat.css          # Chat UI styles
└── README.md                 # This file
```

## Security Features

- Nonces for all frontend requests
- Input/output sanitization
- Rate limiting on chat endpoint
- Admin capability checks
- Secure API key storage (prefers environment variables)

## Performance Features

- Transient caching for sitemaps and product pages
- ETag/Last-Modified support for conditional requests
- Incremental syncing (only re-fetches changed products)
- Conversation state caching
- Chunked product processing

## Troubleshooting

### Sync Not Working

1. Check that the sitemap URL is correct and accessible
2. Verify the menu base URL is correct
3. Check the sync status section for error messages
4. Ensure your server can make outbound HTTP requests
5. Check that rate limits aren't too restrictive

### No Products Found

1. Verify the sitemap contains product URLs
2. Check that product URLs match expected patterns (contain `/product/`, `/menu/`, etc.)
3. Review the parser output - some sites may need custom parsing logic

### Chatbot Not Responding

1. Verify OpenAI API key is configured correctly
2. Check browser console for JavaScript errors
3. Verify REST API is accessible (check permalink settings)
4. Check rate limiting isn't blocking requests

### API Key Issues

1. Ensure environment variable is set correctly
2. For SiteGround, check that `.htaccess` changes are allowed
3. Verify the API key is valid and has credits
4. Check server error logs for authentication errors

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- cURL extension enabled
- OpenAI API account with API key
- Access to Dutchie-hosted menu sitemap

## Support

For issues, feature requests, or questions, please contact the plugin developer or submit an issue in the repository.

## License

GPL v2 or later

## Changelog

### 1.0.0
- Initial release
- Sitemap crawling functionality
- Product parsing (JSON-LD, OpenGraph, embedded JSON)
- AI chatbot with OpenAI integration
- Product recommendation engine
- Admin settings page
- REST API endpoints
- Frontend chat UI
