import { inject, Injectable } from '@angular/core';

import {
  HttpClient,
  HttpHeaders,
  HttpParams,
} from '@angular/common/http';

import { Observable } from 'rxjs';

/*
|--------------------------------------------------------------------------
| YESIAM - ACCESS SERVICE
|--------------------------------------------------------------------------
|
| FASE 21
|
| Flujo completo de un acceso:
|
| 1. Empleado      POST /api/access-requests
| 2. Responsable   POST /api/access-requests/{id}/approve | reject
| 3. Administrador POST /api/access-requests/{id}/grant
| 4. Administrador POST /api/access-grants/{id}/revoke
|
| Angular nunca envía user_id, status,
| granted_by ni campos de control: Laravel
| los toma de la sesión y de la solicitud.
|
*/

export type AccessRequestStatus = 'pending' | 'approved' | 'rejected';

export interface ResourceRef {
  id: number;
  name: string;
  code: string;
  is_active?: boolean;
}

export interface PersonRef {
  id: number;
  name: string;
  email?: string;
  status?: string;
  department_id?: number | null;
}

export interface AccessRequest {
  id: number;
  requester_user_id: number;
  resource_id: number;
  justification: string;
  status: AccessRequestStatus;
  decided_by_user_id: number | null;
  decision_reason: string | null;
  decided_at: string | null;
  created_at: string | null;
  resource?: ResourceRef | null;
  requester?: PersonRef | null;
  decided_by?: PersonRef | null;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

export interface AccessRequestResponse {
  message: string;
  data: AccessRequest;
}

export interface GrantResponse {
  message: string;
  data: {
    id: number;
    status: string;
    user?: PersonRef;
    resource?: ResourceRef;
  };
}

@Injectable({
  providedIn: 'root',
})
export class AccessService {

  private readonly http = inject(HttpClient);

  private readonly requestOptions = {
    headers: new HttpHeaders({
      Accept: 'application/json',
    }),
    withCredentials: true,
  };

  private pageParams(page: number): HttpParams {
    return new HttpParams().set('page', String(page));
  }

  /*
  |--------------------------------------------------------------------------
  | MIS SOLICITUDES
  |--------------------------------------------------------------------------
  */

  myRequests(page = 1): Observable<Paginated<AccessRequest>> {
    return this.http.get<Paginated<AccessRequest>>(
      '/api/access-requests',
      {
        ...this.requestOptions,
        params: this.pageParams(page),
      }
    );
  }

  /*
  |--------------------------------------------------------------------------
  | SOLICITAR ACCESO
  |--------------------------------------------------------------------------
  |
  | Solo resource_id y justification.
  | El solicitante es la sesión actual.
  |
  */

  requestAccess(
    resourceId: number,
    justification: string
  ): Observable<AccessRequestResponse> {
    return this.http.post<AccessRequestResponse>(
      '/api/access-requests',
      {
        resource_id: resourceId,
        justification,
      },
      this.requestOptions
    );
  }

  /*
  |--------------------------------------------------------------------------
  | BANDEJA DEL RESPONSABLE
  |--------------------------------------------------------------------------
  */

  assigned(page = 1): Observable<Paginated<AccessRequest>> {
    return this.http.get<Paginated<AccessRequest>>(
      '/api/access-requests/assigned',
      {
        ...this.requestOptions,
        params: this.pageParams(page),
      }
    );
  }

  approve(
    requestId: number,
    reason: string
  ): Observable<AccessRequestResponse> {
    return this.http.post<AccessRequestResponse>(
      `/api/access-requests/${requestId}/approve`,
      { decision_reason: reason },
      this.requestOptions
    );
  }

  reject(
    requestId: number,
    reason: string
  ): Observable<AccessRequestResponse> {
    return this.http.post<AccessRequestResponse>(
      `/api/access-requests/${requestId}/reject`,
      { decision_reason: reason },
      this.requestOptions
    );
  }

  /*
  |--------------------------------------------------------------------------
  | COLA DE CONCESIÓN
  |--------------------------------------------------------------------------
  */

  readyToGrant(page = 1): Observable<Paginated<AccessRequest>> {
    return this.http.get<Paginated<AccessRequest>>(
      '/api/access-requests/ready-to-grant',
      {
        ...this.requestOptions,
        params: this.pageParams(page),
      }
    );
  }

  grant(requestId: number): Observable<GrantResponse> {
    return this.http.post<GrantResponse>(
      `/api/access-requests/${requestId}/grant`,
      {},
      this.requestOptions
    );
  }

  /*
  |--------------------------------------------------------------------------
  | REVOCACIÓN
  |--------------------------------------------------------------------------
  */

  revoke(grantId: number, reason: string): Observable<GrantResponse> {
    return this.http.post<GrantResponse>(
      `/api/access-grants/${grantId}/revoke`,
      { revocation_reason: reason },
      this.requestOptions
    );
  }
}
