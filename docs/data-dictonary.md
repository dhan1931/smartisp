auth_permissions
Column	Type	Null	Default	Comments
id (Primary)	bigint(20)	No		
code	varchar(100)	No		
description	varchar(255)	Yes	NULL	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No	
uq_auth_permissions_code	BTREE	Yes	No	code	0	A	No	
auth_roles
Column	Type	Null	Default	Comments
id (Primary)	bigint(20)	No		
code	varchar(64)	No		
description	varchar(255)	Yes	NULL	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No	
uq_auth_roles_code	BTREE	Yes	No	code	0	A	No	
auth_role_permissions
Column	Type	Null	Default	Comments
role_id (Primary)	bigint(20)	No		
permission_id (Primary)	bigint(20)	No		
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	role_id	0	A	No	
permission_id	0	A	No
idx_auth_role_permissions_permission	BTREE	No	No	permission_id	0	A	No	
auth_user_roles
Column	Type	Null	Default	Comments
user_id (Primary)	varchar(191)	No		
role_id (Primary)	bigint(20)	No		
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	user_id	0	A	No	
role_id	0	A	No
idx_auth_user_roles_role	BTREE	No	No	role_id	0	A	No	
categories_rows
Column	Type	Null	Default	Comments
id (Primary)	varchar(191)	No		
name	varchar(255)	No		
subcategories	text	Yes	NULL	
updated_at	timestamp	Yes	current_timestamp()	
banner_image_url	varchar(500)	Yes	NULL	
banner_alt	varchar(200)	Yes	NULL	
description	varchar(500)	Yes	NULL	
icon	varchar(64)	No	package	
keywords	longtext	Yes	NULL	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	57	A	No	
categories_v2
Column	Type	Null	Default	Comments
id (Primary)	varchar(191)	No		
name	varchar(255)	No		
parent_id	varchar(191)	Yes	NULL	
slug	varchar(191)	Yes	NULL	
is_active	tinyint(1)	No	1	
created_at	timestamp	Yes	current_timestamp()	
updated_at	timestamp	Yes	current_timestamp()	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No	
uq_categories_v2_slug	BTREE	Yes	No	slug	0	A	Yes	
idx_categories_v2_parent	BTREE	No	No	parent_id	0	A	Yes	
mail_settings
Column	Type	Null	Default	Comments
id (Primary)	tinyint(4)	No	1	
admin_email	varchar(191)	Yes	NULL	
smtp_provider	varchar(40)	Yes	NULL	
smtp_host	varchar(191)	Yes	NULL	
smtp_port	smallint(5)	Yes	NULL	
smtp_user	varchar(191)	Yes	NULL	
smtp_pass	varchar(255)	Yes	NULL	
smtp_secure	tinyint(1)	No	1	
smtp_from	varchar(255)	Yes	NULL	
resend_api_key	varchar(255)	Yes	NULL	
email_from	varchar(255)	Yes	NULL	
updated_at	timestamp	No	current_timestamp()	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	1	A	No	
orders_rows
Column	Type	Null	Default	Comments
id (Primary)	varchar(191)	No		
user_id	varchar(191)	Yes	NULL	
total	decimal(12,2)	No	0.00	
status	varchar(40)	No	pending	
items	text	Yes	NULL	
created_at	timestamp	Yes	current_timestamp()	
updated_at	timestamp	Yes	current_timestamp()	
payment_status	varchar(40)	Yes	NULL	
payment_provider	varchar(60)	Yes	NULL	
shipping	text	Yes	NULL	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	12	A	No	
idx_user	BTREE	No	No	user_id	4	A	Yes	
idx_status_created	BTREE	No	No	status	3	A	No	
created_at	12	A	Yes
idx_orders_user_created	BTREE	No	No	user_id	4	A	Yes	
created_at	12	A	Yes
order_events
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No		
order_id	varchar(191)	No		
from_status	varchar(40)	Yes	NULL	
to_status	varchar(40)	No		
actor	varchar(191)	Yes	NULL	
note	text	Yes	NULL	
created_at	timestamp	No	current_timestamp()	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No	
idx_order	BTREE	No	No	order_id	0	A	No	
created_at	0	A	No
order_items
Column	Type	Null	Default	Comments
id (Primary)	bigint(20)	No		
order_id	varchar(191)	No		
product_id	varchar(191)	Yes	NULL	
product_name	varchar(255)	No		
sku	varchar(191)	Yes	NULL	
quantity	int(10)	No		
unit_price	decimal(12,2)	No		
subtotal	decimal(12,2)	No		
created_at	timestamp	Yes	current_timestamp()	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No	
idx_order_items_order	BTREE	No	No	order_id	0	A	No	
idx_order_items_product	BTREE	No	No	product_id	0	A	Yes	
order_payments
Column	Type	Null	Default	Comments
id (Primary)	bigint(20)	No		
order_id	varchar(191)	No		
provider	varchar(60)	No		
provider_reference	varchar(191)	Yes	NULL	
amount	decimal(12,2)	No		
currency	char(3)	No	USD	
status	varchar(40)	No	pending	
created_at	timestamp	No	current_timestamp()	
updated_at	timestamp	No	current_timestamp()	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No	
uq_order_payment_provider_reference	BTREE	Yes	No	provider	0	A	No	
provider_reference	0	A	Yes
idx_order_payments_order_created	BTREE	No	No	order_id	0	A	No	
created_at	0	A	No
order_shipping_addresses
Column	Type	Null	Default	Comments
order_id (Primary)	varchar(191)	No		
recipient_name	varchar(255)	Yes	NULL	
phone	varchar(40)	Yes	NULL	
country	varchar(100)	Yes	NULL	
province	varchar(120)	Yes	NULL	
city	varchar(120)	Yes	NULL	
address_line1	varchar(255)	Yes	NULL	
address_line2	varchar(255)	Yes	NULL	
postal_code	varchar(30)	Yes	NULL	
notes	text	Yes	NULL	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	order_id	0	A	No	
password_resets
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No		
email	varchar(191)	No		
token	varchar(191)	No		
expires_at	datetime	No		
created_at	timestamp	Yes	current_timestamp()	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	1	A	No	
token	BTREE	Yes	No	token	1	A	No	
idx_token	BTREE	No	No	token	1	A	No	
idx_email	BTREE	No	No	email	1	A	No	
password_resets_rows
Column	Type	Null	Default	Comments
COL 1	varchar(64)	Yes	NULL	
COL 2	varchar(36)	Yes	NULL	
COL 3	varchar(29)	Yes	NULL	
COL 4	varchar(29)	Yes	NULL	
No index defined!

products_rows
Column	Type	Null	Default	Comments
id (Primary)	varchar(191)	No		
name	text	Yes	NULL	
description	text	Yes	NULL	
price	decimal(10,2)	No	0.00	
category	text	Yes	NULL	
image_url	text	Yes	NULL	
visible	tinyint(1)	No	1	
created_at	timestamp	Yes	NULL	
updated_at	timestamp	Yes	NULL	
external_url	text	Yes	NULL	
sku	text	Yes	NULL	
subcategory	text	Yes	NULL	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	2657	A	No	
idx_visible_created	BTREE	No	No	visible	2	A	No	
created_at	2	A	Yes
idx_products_visible_category_subcategory	BTREE	No	No	visible	2	A	No	
category (100)	59	A	Yes
subcategory (100)	64	A	Yes
wdbi_visible_category_100_updated_at_c7ef61d40dac	BTREE	No	No	visible	2	A	No	
category (100)	59	A	Yes
updated_at	120	A	Yes
product_categories
Column	Type	Null	Default	Comments
product_id (Primary)	varchar(191)	No		
category_id (Primary)	varchar(191)	No		
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	product_id	0	A	No	
category_id	0	A	No
idx_product_categories_category	BTREE	No	No	category_id	0	A	No	
product_images
Column	Type	Null	Default	Comments
id (Primary)	bigint(20)	No		
product_id	varchar(191)	No		
image_url	text	No		
sort_order	int(10)	No	0	
is_primary	tinyint(1)	No	0	
created_at	timestamp	Yes	current_timestamp()	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No	
idx_product_images_product_order	BTREE	No	No	product_id	0	A	No	
sort_order	0	A	No
product_inventory
Column	Type	Null	Default	Comments
product_id (Primary)	varchar(191)	No		
quantity_available	int(10)	No	0	
quantity_reserved	int(10)	No	0	
updated_at	timestamp	No	current_timestamp()	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	product_id	0	A	No	
settings_rows
Column	Type	Null	Default	Comments
id (Primary)	int(11)	No		
setting_key	varchar(191)	No		
setting_value	longtext	Yes	NULL	
updated_at	timestamp	Yes	current_timestamp()	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	188	A	No	
setting_key	BTREE	Yes	No	setting_key	188	A	No	
storefront_campaigns
Column	Type	Null	Default	Comments
id (Primary)	bigint(20)	No		
code	varchar(64)	No		
name	varchar(150)	No		
placement	varchar(40)	No	home	
is_active	tinyint(1)	No	1	
rotation_seconds	smallint(5)	No	7	
starts_at	datetime	Yes	NULL	
ends_at	datetime	Yes	NULL	
created_at	timestamp	No	current_timestamp()	
updated_at	timestamp	No	current_timestamp()	
display_mode	varchar(24)	No	carousel	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	1	A	No	
uq_storefront_campaigns_code	BTREE	Yes	No	code	1	A	No	
idx_storefront_campaigns_active_dates	BTREE	No	No	placement	1	A	No	
is_active	1	A	No
starts_at	1	A	Yes
ends_at	1	A	Yes
storefront_campaign_products
Column	Type	Null	Default	Comments
campaign_id (Primary)	bigint(20)	No		
product_id (Primary)	varchar(191)	No		
sort_order	smallint(5)	No	0	
created_at	timestamp	No	current_timestamp()	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	campaign_id	0	A	No	
product_id	0	A	No
idx_storefront_campaign_products_order	BTREE	No	No	campaign_id	0	A	No	
sort_order	0	A	No
idx_storefront_campaign_products_product	BTREE	No	No	product_id	0	A	No	
storefront_campaign_slides
Column	Type	Null	Default	Comments
id (Primary)	bigint(20)	No		
campaign_id	bigint(20)	No		
eyebrow	varchar(100)	Yes	NULL	
title	varchar(180)	No		
subtitle	varchar(500)	Yes	NULL	
button_text	varchar(60)	No	Explorar	
target_url	varchar(500)	No	/tienda.html#catalogo	
image_url	varchar(500)	No		
image_alt	varchar(200)	Yes	NULL	
sort_order	smallint(5)	No	0	
is_active	tinyint(1)	No	1	
created_at	timestamp	No	current_timestamp()	
updated_at	timestamp	No	current_timestamp()	
image_fit	varchar(12)	No	cover	
starts_at	datetime	Yes	NULL	
ends_at	datetime	Yes	NULL	
badge_label	varchar(60)	Yes	NULL	
badge_tone	varchar(20)	No	discount	
image_opacity	tinyint(3)	No	100	
overlay_opacity	tinyint(3)	No	18	
image_interval_seconds	smallint(5)	No	5	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	3	A	No	
idx_storefront_campaign_slides_order	BTREE	No	No	campaign_id	1	A	No	
is_active	1	A	No
sort_order	3	A	No
storefront_campaign_slide_images
Column	Type	Null	Default	Comments
id (Primary)	bigint(20)	No		
slide_id	bigint(20)	No		
image_url	varchar(500)	No		
image_alt	varchar(200)	Yes	NULL	
sort_order	smallint(5)	No	0	
is_primary	tinyint(1)	No	0	
created_at	timestamp	No	current_timestamp()	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	3	A	No	
uq_campaign_slide_image_url	BTREE	Yes	No	slide_id	3	A	No	
image_url	3	A	No
idx_campaign_slide_images_order	BTREE	No	No	slide_id	3	A	No	
sort_order	3	A	No
storefront_promo_chips
Column	Type	Null	Default	Comments
id (Primary)	bigint(20)	No		
label	varchar(60)	No		
icon	varchar(40)	No	tag	
target_url	varchar(500)	No	/tienda.html#catalogo	
sort_order	smallint(5)	No	0	
is_active	tinyint(1)	No	1	
starts_at	datetime	Yes	NULL	
ends_at	datetime	Yes	NULL	
created_at	timestamp	No	current_timestamp()	
updated_at	timestamp	No	current_timestamp()	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	0	A	No	
idx_storefront_promo_chips_active	BTREE	No	No	is_active	0	A	No	
sort_order	0	A	No
starts_at	0	A	Yes
ends_at	0	A	Yes
users_rows
Column	Type	Null	Default	Comments
id (Primary)	varchar(191)	No		
email	varchar(191)	No		
password_hash	text	Yes	NULL	
name	text	Yes	NULL	
surname	text	Yes	NULL	
phone	text	Yes	NULL	
created_at	timestamp	Yes	current_timestamp()	
role	varchar(40)	No	customer	
Indexes
Keyname	Type	Unique	Packed	Column	Cardinality	Collation	Null	Comment
PRIMARY	BTREE	Yes	No	id	4	A	No	
idx_email	BTREE	Yes	No	email	4