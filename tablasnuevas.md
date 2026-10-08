1. Validaciones previas
El archivo incluye consultas SQL para comprobar lo siguiente antes de migrar:
Validación	Riesgo que detecta
Versión MySQL/MariaDB	Incompatibilidades de sintaxis
Motor de las tablas	Problemas al crear claves foráneas
Usuarios sin relación en pedidos	Registros huérfanos
Correos duplicados	Conflictos de unicidad
SKU duplicados	Identificadores inconsistentes
Precios negativos	Datos comerciales inválidos
Productos sin nombre	Catálogo incompleto
Formato de items y shipping	Riesgos al migrar pedidos
Tabla password_resets_rows	Posible importación incorrecta
Importante: ejecuta primero únicamente la Fase 0. Antes de crear relaciones, confirma también que los identificadores existentes usan tipos y colaciones compatibles.
2. Tablas nuevas incluidas
Tabla	Propósito
categories_v2	Jerarquía de categorías
product_categories	Relaciones producto-categoría
product_images	Galería de imágenes
product_inventory	Control de existencias
order_items	Detalle individual de compras
order_shipping_addresses	Dirección histórica de entrega
order_payments	Pagos y referencias
order_status_history	Historial de cambios
password_reset_tokens	Recuperación segura de cuentas
auth_roles	Roles del sistema
auth_permissions	Permisos disponibles
auth_user_roles	Asignación de roles
auth_role_permissions	Permisos por rol
Por ejemplo, el nuevo inventario queda así:
CREATE TABLE product_inventory (
    product_id VARCHAR(191) NOT NULL PRIMARY KEY,
    quantity_available INT UNSIGNED NOT NULL DEFAULT 0,
    quantity_reserved INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_inventory_product
        FOREIGN KEY (product_id)
        REFERENCES products_rows(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


3. Mejoras esperadas
Inventario controlado
Se podrán controlar existencias disponibles y reservadas, evitando ventas simultáneas sin disponibilidad cuando el backend use transacciones correctas.



Pedidos consultables
Podrás consultar cantidades vendidas por producto, precios históricos y detalles de cada compra sin depender de un único campo TEXT.



Reportería comercial
Una vez migrados los datos podrás construir reportes de productos más vendidos, ventas por categoría, ingresos por período y estados de pedidos.



Seguridad y trazabilidad
Recuperación mediante tokens hasheados, estructura para permisos específicos y registros de cambios de estado.



4. Orden de ejecución
1. Realizar respaldo completo de la base de datos y verificar su restauración.
2. Ejecutar la Fase 0, únicamente consultas de diagnóstico.
3. Resolver inconsistencias y confirmar compatibilidad de claves foráneas.
4. Ejecutar la Fase 1, creación de las 13 tablas adicionales.
5. Ejecutar selectivamente la Fase 2, migrando los datos existentes según su formato real.
6. Coordinar los cambios opcionales de la Fase 3 con el despliegue del backend.
7. Ejecutar la Fase 4 y verificar relaciones, datos y funcionamiento de las aplicaciones.
El script está estructurado para ser ejecutado por fases, pero no se ha probado contra tu servidor real. Las sentencias de la Fase 3 están comentadas porque necesitan validación previa y no deben repetirse indiscriminadamente.
Tampoco elimina items, shipping, category ni otros campos originales: primero habría que migrar su información, comparar resultados y adaptar la aplicación.
Resultado esperado: una base de datos más consistente y preparada para nuevas funcionalidades, sin obligarte a reconstruir el e-commerce desde cero. La mejora efectiva de rendimiento dependerá de las consultas e índices que utilice el backend.

## Resultado de Fase 0 (Hostinger, 2026-10-08)

Consultas de solo lectura ejecutadas en `u606699314_smart_isp`. No se modificaron tablas ni datos.

| Verificación | Resultado |
|---|---|
| Servidor | MariaDB 11.8.9 |
| Tablas base | InnoDB; `users_rows` 4, `products_rows` 2775, `orders_rows` 12, `categories_rows` 8 |
| Pedidos sin usuario existente | 0 |
| Grupos de emails duplicados | 0 |
| Productos sin nombre / precio negativo | 0 / 0 |
| Grupos de SKU repetidos | 19; no imponer unicidad al SKU todavía |
| JSON inválido en items, shipping o subcategorías | 0 |
| Forma de los datos | `items`: 11 arrays y 1 objeto; `shipping`: 12 objetos; subcategorías: 8 arrays |
| `password_resets_rows` | 3 filas con columnas genéricas `COL 1` a `COL 4`; conservar e investigar |
| `order_events` | No existe actualmente en la base de producción |

La base usa `utf8mb4_unicode_ci` como collation predeterminada. `products_rows`, `orders_rows` y `users_rows` usan esa collation; `categories_rows`, `settings_rows` y algunas tablas de recuperación usan `utf8mb4_uca1400_ai_ci`. Las nuevas tablas heredarán la collation predeterminada; las claves foráneas propuestas apuntan a productos, pedidos, usuarios y a `categories_v2`, por lo que sus columnas referenciadas deben mantener tipos y collations compatibles. No referenciar directamente `categories_rows.id` sin ajustar esa diferencia.

Antes de la Fase 1, decidir si el historial será `order_events` o `order_status_history`: el SQL nuevo propone esta última, mientras la migración 003 del repositorio crea la primera, aunque todavía no está en producción. No ejecutar ambas como historiales paralelos.
