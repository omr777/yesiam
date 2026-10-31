import { inject, Injectable } from '@angular/core';

import {
  HttpClient,
  HttpHeaders,
} from '@angular/common/http';

import { Observable, map } from 'rxjs';

/*
|--------------------------------------------------------------------------
| YESIAM - DASHBOARD SERVICE
|--------------------------------------------------------------------------
|
| FASE 21
|
| GET /api/dashboard
|
| Cada sección es opcional: Laravel solo la
| incluye si la sesión tiene el permiso.
| Una sección ausente NO significa cero.
|
*/

export interface DashboardSummary {
  my_access: {
    active_grants: number;
  };
  identities?: {
    active: number;
    suspended: number;
    disabled: number;
  };
  departments?: {
    active: number;
    inactive: number;
  };
  resources?: {
    active: number;
    restricted: number;
  };
  my_requests?: {
    pending: number;
    approved: number;
    rejected: number;
  };
  approvals?: {
    pending: number;
  };
  provisioning?: {
    ready_to_grant: number;
  };
  access?: {
    active_grants: number;
    revoked_grants: number;
  };
  recent_activity?: {
    id: number;
    action: string;
    entity_type: string;
    entity_id: number;
    outcome: string;
    actor: { id: number; name: string } | null;
    created_at: string | null;
  }[];
}

@Injectable({
  providedIn: 'root',
})
export class DashboardService {

  private readonly http = inject(HttpClient);

  summary(): Observable<DashboardSummary> {
    return this.http.get<{ data: DashboardSummary }>(
      '/api/dashboard',
      {
        headers: new HttpHeaders({
          Accept: 'application/json',
        }),
        withCredentials: true,
      }
    ).pipe(
      map((response) => response.data)
    );
  }
}
