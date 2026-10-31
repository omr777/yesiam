import { inject, Injectable } from '@angular/core';

import {
  HttpClient,
  HttpHeaders,
  HttpParams,
} from '@angular/common/http';

import { Observable } from 'rxjs';

import { Paginated } from '../access/access.service';

/*
|--------------------------------------------------------------------------
| YESIAM - AUDIT SERVICE
|--------------------------------------------------------------------------
|
| FASE 21
|
| GET /api/audit-logs
|
| Solo lectura. La bitácora no se puede
| crear ni modificar desde el cliente.
|
*/

export interface AuditLogEntry {
  id: number;
  actor_user_id: number | null;
  action: string;
  entity_type: string;
  entity_id: number;
  outcome: string;
  metadata: Record<string, unknown> | null;
  ip_address: string | null;
  created_at: string | null;
  actor?: { id: number; name: string } | null;
}

export interface AuditFilters {
  action?: string;
  entityType?: string;
  entityId?: number | null;
  page?: number;
  perPage?: number;
}

@Injectable({
  providedIn: 'root',
})
export class AuditService {

  private readonly http = inject(HttpClient);

  private readonly requestOptions = {
    headers: new HttpHeaders({
      Accept: 'application/json',
    }),
    withCredentials: true,
  };

  list(filters: AuditFilters = {}): Observable<Paginated<AuditLogEntry>> {
    let params = new HttpParams()
      .set('page', String(filters.page ?? 1))
      .set('per_page', String(filters.perPage ?? 25));

    if (filters.action) {
      params = params.set('action', filters.action);
    }

    if (filters.entityType) {
      params = params.set('entity_type', filters.entityType);
    }

    if (filters.entityId) {
      params = params.set('entity_id', String(filters.entityId));
    }

    return this.http.get<Paginated<AuditLogEntry>>(
      '/api/audit-logs',
      {
        ...this.requestOptions,
        params,
      }
    );
  }
}
