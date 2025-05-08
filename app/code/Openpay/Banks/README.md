# Openpay-Magento2-Banks

Módulo para pagos vía SPEI con Openpay para Magento2 (soporte hasta v2.3.5)


## Instalación

1. Ir a la carpeta raíz del proyecto de Magento y seguir los siguiente pasos:

**Para versiones de Magento < 2.3.0**
```bash    
composer require openpay/magento2-banks:~3.1.0
```

**Para versiones de Magento >= 2.3.0**
```bash    
composer require openpay/magento2-banks:~3.5.0
```

**Para versiones de Magento >= 2.3.5**
```bash
composer require openpay/magento2-banks:4.2.*
```

2. Después se procede a habilitar el módulo,actualizar y limpiar cache de la plataforma.

```bash    
php bin/magento module:enable Openpay_Banks --clear-static-content
php bin/magento setup:upgrade
php bin/magento cache:clean
```

3. Para configurar el módulo desde el panel de administración de la tienda diríjase a: Stores > Configuration > Sales > Payment Methods
