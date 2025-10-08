# MDM System Production Deployment

This directory contains all the necessary files and scripts for deploying the MDM System to production.

## Prerequisites

- Docker and Docker Compose installed
- Minimum 8GB RAM, 4 CPU cores
- At least 50GB free disk space
- SSL certificates (if using HTTPS)
- Environment variables configured

## Quick Start

1. **Copy environment file and configure:**

   ```bash
   cp .env.example .env
   # Edit .env with your production values
   ```

2. **Set up SSL certificates (if using HTTPS):**

   ```bash
   mkdir -p config/nginx/ssl
   # Copy your SSL certificates to config/nginx/ssl/
   ```

3. **Deploy the system:**

   ```bash
   ./scripts/deploy.sh
   ```

4. **Set up monitoring:**

   ```bash
   ./scripts/monitoring-setup.sh
   ```

5. **Configure automated backups:**
   ```bash
   # Add to crontab
   0 2 * * * /opt/mdm/scripts/backup.sh
   ```

## Directory Structure

```
deployment/production/
├── config/                     # Configuration files
│   ├── mysql/                 # MySQL configuration
│   ├── nginx/                 # Nginx configuration
│   ├── redis/                 # Redis configuration
│   ├── prometheus/            # Prometheus configuration
│   └── grafana/              # Grafana dashboards
├── scripts/                   # Deployment and maintenance scripts
│   ├── deploy.sh             # Main deployment script
│   ├── backup.sh             # Backup script
│   ├── setup-replication.sh  # MySQL replication setup
│   └── monitoring-setup.sh   # Monitoring setup
├── docker-compose.prod.yml    # Production Docker Compose
├── .env.example              # Environment variables template
└── README.md                 # This file
```

## Services

The production deployment includes the following services:

- **mdm-app**: Main MDM application
- **mdm-db-master**: MySQL master database
- **mdm-db-slave**: MySQL slave database (read replica)
- **mdm-redis**: Redis cache
- **mdm-nginx**: Nginx reverse proxy
- **mdm-prometheus**: Prometheus monitoring
- **mdm-grafana**: Grafana dashboards

## Environment Variables

Key environment variables that must be configured:

```bash
# Database
DB_NAME=mdm_production
DB_USER=mdm_user
DB_PASSWORD=your_secure_password
DB_ROOT_PASSWORD=your_root_password
REPLICATION_PASSWORD=your_replication_password

# Redis
REDIS_PASSWORD=your_redis_password

# Monitoring
GRAFANA_PASSWORD=your_grafana_password

# Backup
BACKUP_S3_BUCKET=mdm-backups
AWS_ACCESS_KEY_ID=your_aws_key
AWS_SECRET_ACCESS_KEY=your_aws_secret

# Alerts
SLACK_WEBHOOK_URL=https://hooks.slack.com/...
EMAIL_ALERTS_TO=admin@company.com
```

## Deployment Process

The deployment script (`scripts/deploy.sh`) performs the following steps:

1. **Pre-deployment checks**: Validates environment and prerequisites
2. **Database backup**: Creates a backup before deployment
3. **Image pull**: Downloads latest Docker images
4. **Database migrations**: Runs any pending migrations
5. **Service deployment**: Deploys services with zero downtime
6. **Health verification**: Verifies all services are healthy
7. **Cleanup**: Removes old images and backups

## Monitoring

### Grafana Dashboards

Access Grafana at `http://your-server:3000` with credentials:

- Username: `admin`
- Password: Set in `GRAFANA_PASSWORD` environment variable

Available dashboards:

- **MDM System Overview**: Application metrics and performance
- **Database Monitoring**: MySQL master/slave status and performance

### Prometheus Metrics

Access Prometheus at `http://your-server:9090`

Monitored metrics include:

- Application response times and error rates
- Database connections and query performance
- System resources (CPU, memory, disk)
- Redis cache performance

### Alerts

Configured alerts for:

- Application downtime
- High response times
- Database connectivity issues
- Replication lag
- High resource usage

## Backup and Recovery

### Automated Backups

The backup script (`scripts/backup.sh`) creates:

- Database dumps with compression
- Application file backups
- S3 uploads (if configured)
- Automatic cleanup of old backups

### Manual Backup

```bash
./scripts/backup.sh
```

### Recovery

To restore from backup:

```bash
# Stop services
docker-compose -f docker-compose.prod.yml down

# Restore database
gunzip -c /opt/mdm/backups/mdm_backup_YYYYMMDD_HHMMSS.sql.gz | \
  docker-compose -f docker-compose.prod.yml exec -T mdm-db-master \
  mysql -u root -p${DB_ROOT_PASSWORD} ${DB_NAME}

# Start services
docker-compose -f docker-compose.prod.yml up -d
```

## Security

### SSL/TLS

- Configure SSL certificates in `config/nginx/ssl/`
- Update `nginx.conf` with your domain name
- Ensure certificates are renewed regularly

### Database Security

- Use strong passwords for all database users
- Enable SSL for database connections
- Restrict database access to application servers only

### Application Security

- Run containers as non-root users
- Use secrets management for sensitive data
- Enable audit logging for all changes

## Troubleshooting

### Common Issues

1. **Services not starting**: Check logs with `docker-compose logs`
2. **Database connection issues**: Verify credentials and network connectivity
3. **High memory usage**: Monitor with Grafana and adjust container limits
4. **Replication lag**: Check MySQL slave status and network latency

### Log Locations

- Application logs: `/opt/mdm/logs/`
- Nginx logs: `/opt/mdm/logs/nginx/`
- System logs: `/var/log/mdm-*.log`

### Health Checks

```bash
# Check service status
docker-compose -f docker-compose.prod.yml ps

# Test application health
curl http://localhost:8080/health

# Test API endpoints
curl http://localhost:8080/api/health
```

## Maintenance

### Regular Tasks

- Monitor system resources and performance
- Review and rotate logs
- Update Docker images
- Test backup and recovery procedures
- Review security configurations

### Updates

To update the system:

1. Test in staging environment
2. Create backup
3. Run deployment script
4. Verify all services are healthy
5. Monitor for issues

## Support

For issues and questions:

- Check logs and monitoring dashboards
- Review troubleshooting section
- Contact system administrators
- Refer to technical documentation
