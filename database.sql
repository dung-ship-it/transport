--
-- PostgreSQL database dump
--

\restrict qS03myfsUMGKwFcatYWKL3Z8qg01dxbyaaR3ajZgIKJn5rl0h0TEKBByrqX6Etk

-- Dumped from database version 18.3
-- Dumped by pg_dump version 18.3

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


--
-- Name: uuid-ossp; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS "uuid-ossp" WITH SCHEMA public;


--
-- Name: EXTENSION "uuid-ossp"; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION "uuid-ossp" IS 'generate universally unique identifiers (UUIDs)';


--
-- Name: calc_combo_amount(integer, integer, integer, integer); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.calc_combo_amount(p_customer_id integer, p_vehicle_id integer, p_year integer, p_month integer) RETURNS TABLE(total_km numeric, combo_price numeric, over_km numeric, over_km_amount numeric, toll_amount numeric, holiday_amount numeric, sunday_amount numeric, grand_total numeric)
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_rule          price_rules%ROWTYPE;
    v_book_id       INTEGER;
    v_total_km      NUMERIC := 0;
    v_toll          NUMERIC := 0;
    v_holiday_km    NUMERIC := 0;
    v_sunday_km     NUMERIC := 0;
BEGIN
    -- Lấy bảng giá active đầu tháng
    v_book_id := get_active_price_book(
        p_customer_id,
        make_date(p_year, p_month, 1)
    );
    IF v_book_id IS NULL THEN RETURN; END IF;

    -- Lấy price rule của xe này
    SELECT * INTO v_rule FROM price_rules
    WHERE price_book_id = v_book_id
      AND vehicle_id    = p_vehicle_id
    LIMIT 1;
    IF NOT FOUND THEN RETURN; END IF;

    -- Tổng KM confirmed trong tháng
    SELECT COALESCE(SUM(distance_km), 0),
           COALESCE(SUM(toll_amount), 0)
    INTO v_total_km, v_toll
    FROM trips
    WHERE customer_id = p_customer_id
      AND vehicle_id  = p_vehicle_id
      AND status IN ('completed','confirmed')
      AND EXTRACT(MONTH FROM trip_date) = p_month
      AND EXTRACT(YEAR  FROM trip_date) = p_year;

    -- KM ngày lễ & chủ nhật (để tính phụ phí)
    SELECT COALESCE(SUM(t.distance_km), 0)
    INTO v_holiday_km
    FROM trips t
    JOIN holidays h ON h.holiday_date = t.trip_date
    WHERE t.customer_id = p_customer_id
      AND t.vehicle_id  = p_vehicle_id
      AND t.status IN ('completed','confirmed')
      AND EXTRACT(MONTH FROM t.trip_date) = p_month
      AND EXTRACT(YEAR  FROM t.trip_date) = p_year;

    SELECT COALESCE(SUM(distance_km), 0)
    INTO v_sunday_km
    FROM trips
    WHERE customer_id = p_customer_id
      AND vehicle_id  = p_vehicle_id
      AND is_sunday   = TRUE
      AND status IN ('completed','confirmed')
      AND EXTRACT(MONTH FROM trip_date) = p_month
      AND EXTRACT(YEAR  FROM trip_date) = p_year;

    -- Tính toán
    total_km       := v_total_km;
    combo_price    := COALESCE(v_rule.combo_monthly_price, 0);
    over_km        := GREATEST(0, v_total_km - COALESCE(v_rule.combo_km_limit, 0));
    over_km_amount := over_km * COALESCE(v_rule.over_km_price, 0);
    toll_amount    := CASE WHEN v_rule.toll_included THEN 0 ELSE v_toll END;
    holiday_amount := v_holiday_km * COALESCE(v_rule.standard_price_per_km, 0)
                      * COALESCE(v_rule.holiday_surcharge, 0) / 100;
    sunday_amount  := v_sunday_km  * COALESCE(v_rule.standard_price_per_km, 0)
                      * COALESCE(v_rule.sunday_surcharge, 0) / 100;
    grand_total    := combo_price + over_km_amount + toll_amount
                      + holiday_amount + sunday_amount;
    RETURN NEXT;
END;
$$;


ALTER FUNCTION public.calc_combo_amount(p_customer_id integer, p_vehicle_id integer, p_year integer, p_month integer) OWNER TO postgres;

--
-- Name: generate_customer_code(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.generate_customer_code() RETURNS trigger
    LANGUAGE plpgsql
    AS $_$
DECLARE new_code VARCHAR(20); max_num INTEGER;
BEGIN
    SELECT COALESCE(MAX(CAST(SUBSTRING(customer_code FROM 3) AS INTEGER)), 0)
    INTO max_num FROM customers
    WHERE customer_code ~ '^KH[0-9]+$';
    NEW.customer_code := 'KH' || LPAD((max_num + 1)::TEXT, 3, '0');
    RETURN NEW;
END;
$_$;


ALTER FUNCTION public.generate_customer_code() OWNER TO postgres;

--
-- Name: generate_employee_code(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.generate_employee_code() RETURNS trigger
    LANGUAGE plpgsql
    AS $_$
DECLARE
    new_code VARCHAR(20);
    max_num  INTEGER;
BEGIN
    SELECT COALESCE(MAX(CAST(SUBSTRING(employee_code FROM 3) AS INTEGER)), 0)
    INTO max_num
    FROM users
    WHERE employee_code IS NOT NULL AND employee_code ~ '^NV[0-9]+$';

    new_code := 'NV' || LPAD((max_num + 1)::TEXT, 3, '0');
    NEW.employee_code := new_code;
    RETURN NEW;
END;
$_$;


ALTER FUNCTION public.generate_employee_code() OWNER TO postgres;

--
-- Name: generate_trip_code(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.generate_trip_code() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    new_code VARCHAR(20);
    max_num  INTEGER;
    prefix   VARCHAR(10);
BEGIN
    prefix := 'CH' || TO_CHAR(NOW(), 'YYMM');
    SELECT COALESCE(MAX(
        CAST(SUBSTRING(trip_code FROM LENGTH(prefix)+1) AS INTEGER)
    ), 0)
    INTO max_num
    FROM trips
    WHERE trip_code LIKE prefix || '%';
    NEW.trip_code := prefix || LPAD((max_num + 1)::TEXT, 4, '0');
    RETURN NEW;
END;
$$;


ALTER FUNCTION public.generate_trip_code() OWNER TO postgres;

--
-- Name: get_active_price_book(integer, date); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.get_active_price_book(p_customer_id integer, p_date date) RETURNS integer
    LANGUAGE sql
    AS $$
    SELECT pb.id FROM price_books pb
    WHERE pb.customer_id = p_customer_id
      AND pb.is_active   = TRUE
      AND pb.valid_from  <= p_date
      AND (pb.valid_to IS NULL OR pb.valid_to >= p_date)
    ORDER BY pb.valid_from DESC
    LIMIT 1;
$$;


ALTER FUNCTION public.get_active_price_book(p_customer_id integer, p_date date) OWNER TO postgres;

--
-- Name: grant_permission(character varying, character varying, character varying); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.grant_permission(p_role character varying, p_module character varying, p_action character varying) RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
    INSERT INTO role_permissions (role_id, permission_id)
    SELECT r.id, p.id
    FROM roles r, permissions p
    WHERE r.name = p_role
      AND p.module = p_module
      AND p.action = p_action
    ON CONFLICT DO NOTHING;
END;
$$;


ALTER FUNCTION public.grant_permission(p_role character varying, p_module character varying, p_action character varying) OWNER TO postgres;

--
-- Name: update_updated_at(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.update_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;


ALTER FUNCTION public.update_updated_at() OWNER TO postgres;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: customer_statements; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.customer_statements (
    id integer NOT NULL,
    customer_id integer NOT NULL,
    statement_type character varying(20) DEFAULT 'monthly'::character varying,
    period_from date NOT NULL,
    period_to date NOT NULL,
    total_trips integer DEFAULT 0,
    total_amount numeric(15,2) DEFAULT 0,
    paid_amount numeric(15,2) DEFAULT 0,
    debt_amount numeric(15,2) DEFAULT 0,
    status character varying(20) DEFAULT 'draft'::character varying,
    sent_at timestamp with time zone,
    confirmed_at timestamp with time zone,
    confirmed_by integer,
    created_by integer,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.customer_statements OWNER TO postgres;

--
-- Name: customer_statements_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.customer_statements_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.customer_statements_id_seq OWNER TO postgres;

--
-- Name: customer_statements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.customer_statements_id_seq OWNED BY public.customer_statements.id;


--
-- Name: customer_users; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.customer_users (
    id integer NOT NULL,
    customer_id integer NOT NULL,
    user_id integer NOT NULL,
    role character varying(20) DEFAULT 'viewer'::character varying,
    is_primary boolean DEFAULT false,
    is_active boolean DEFAULT true,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone
);


ALTER TABLE public.customer_users OWNER TO postgres;

--
-- Name: customer_users_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.customer_users_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.customer_users_id_seq OWNER TO postgres;

--
-- Name: customer_users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.customer_users_id_seq OWNED BY public.customer_users.id;


--
-- Name: customers; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.customers (
    id integer NOT NULL,
    customer_code character varying(20),
    company_name character varying(300) NOT NULL,
    short_name character varying(100),
    tax_code character varying(20),
    legal_address text,
    invoice_address text,
    legal_representative character varying(200),
    representative_title character varying(100),
    primary_contact_name character varying(200),
    primary_contact_phone character varying(20),
    primary_contact_email character varying(200),
    bank_name character varying(100),
    bank_account_number character varying(50),
    bank_branch character varying(200),
    payment_terms integer DEFAULT 30,
    billing_cycle character varying(20) DEFAULT 'monthly'::character varying,
    billing_day character varying(50),
    is_active boolean DEFAULT true,
    note text,
    created_by integer,
    updated_by integer,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone
);


ALTER TABLE public.customers OWNER TO postgres;

--
-- Name: customers_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.customers_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.customers_id_seq OWNER TO postgres;

--
-- Name: customers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.customers_id_seq OWNED BY public.customers.id;


--
-- Name: departments; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.departments (
    id integer NOT NULL,
    name character varying(100) NOT NULL,
    code character varying(20),
    manager_id integer,
    is_active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.departments OWNER TO postgres;

--
-- Name: departments_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.departments_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.departments_id_seq OWNER TO postgres;

--
-- Name: departments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.departments_id_seq OWNED BY public.departments.id;


--
-- Name: driver_kpi; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.driver_kpi (
    id integer NOT NULL,
    driver_id integer NOT NULL,
    period_month integer NOT NULL,
    period_year integer NOT NULL,
    total_trips integer DEFAULT 0,
    total_km numeric(10,2) DEFAULT 0,
    total_revenue numeric(15,2) DEFAULT 0,
    kpi_score numeric(5,2) DEFAULT 0,
    kpi_target numeric(5,2) DEFAULT 100,
    bonus_amount numeric(15,2) DEFAULT 0,
    penalty_amount numeric(15,2) DEFAULT 0,
    note text,
    calculated_by integer,
    calculated_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.driver_kpi OWNER TO postgres;

--
-- Name: driver_kpi_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.driver_kpi_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.driver_kpi_id_seq OWNER TO postgres;

--
-- Name: driver_kpi_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.driver_kpi_id_seq OWNED BY public.driver_kpi.id;


--
-- Name: driver_ratings; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.driver_ratings (
    id integer NOT NULL,
    driver_id integer NOT NULL,
    trip_id integer,
    customer_id integer,
    rating numeric(3,1) NOT NULL,
    comment text,
    rated_by integer,
    rated_at timestamp with time zone DEFAULT now(),
    is_complaint boolean DEFAULT false,
    rating_punctual smallint,
    rating_attitude smallint,
    rating_cargo smallint,
    rating_vehicle smallint,
    CONSTRAINT driver_ratings_rating_attitude_check CHECK (((rating_attitude >= 1) AND (rating_attitude <= 5))),
    CONSTRAINT driver_ratings_rating_cargo_check CHECK (((rating_cargo >= 1) AND (rating_cargo <= 5))),
    CONSTRAINT driver_ratings_rating_check CHECK (((rating >= (1)::numeric) AND (rating <= (5)::numeric))),
    CONSTRAINT driver_ratings_rating_punctual_check CHECK (((rating_punctual >= 1) AND (rating_punctual <= 5))),
    CONSTRAINT driver_ratings_rating_vehicle_check CHECK (((rating_vehicle >= 1) AND (rating_vehicle <= 5)))
);


ALTER TABLE public.driver_ratings OWNER TO postgres;

--
-- Name: driver_ratings_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.driver_ratings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.driver_ratings_id_seq OWNER TO postgres;

--
-- Name: driver_ratings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.driver_ratings_id_seq OWNED BY public.driver_ratings.id;


--
-- Name: drivers; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.drivers (
    id integer NOT NULL,
    user_id integer NOT NULL,
    license_number character varying(50),
    license_class character varying(10),
    license_expiry date,
    hire_date date,
    base_salary numeric(15,2) DEFAULT 0,
    kpi_target numeric(5,2) DEFAULT 100,
    is_active boolean DEFAULT true,
    created_by integer,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.drivers OWNER TO postgres;

--
-- Name: drivers_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.drivers_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.drivers_id_seq OWNER TO postgres;

--
-- Name: drivers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.drivers_id_seq OWNED BY public.drivers.id;


--
-- Name: employee_shifts; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.employee_shifts (
    id integer NOT NULL,
    user_id integer NOT NULL,
    shift_id integer NOT NULL,
    effective_date date NOT NULL,
    end_date date,
    created_by integer,
    created_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.employee_shifts OWNER TO postgres;

--
-- Name: employee_shifts_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.employee_shifts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.employee_shifts_id_seq OWNER TO postgres;

--
-- Name: employee_shifts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.employee_shifts_id_seq OWNED BY public.employee_shifts.id;


--
-- Name: fuel_logs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.fuel_logs (
    id integer NOT NULL,
    driver_id integer NOT NULL,
    vehicle_id integer NOT NULL,
    log_date date DEFAULT CURRENT_DATE NOT NULL,
    km_before numeric(10,2),
    km_after numeric(10,2),
    km_driven numeric(10,2) GENERATED ALWAYS AS ((km_after - km_before)) STORED,
    liters_filled numeric(8,2) NOT NULL,
    amount numeric(12,2) NOT NULL,
    price_per_liter numeric(8,2) GENERATED ALWAYS AS (
CASE
    WHEN (liters_filled > (0)::numeric) THEN round((amount / liters_filled), 0)
    ELSE (0)::numeric
END) STORED,
    fuel_efficiency numeric(8,2) GENERATED ALWAYS AS (
CASE
    WHEN ((km_after - km_before) > (0)::numeric) THEN round(((liters_filled / (km_after - km_before)) * (100)::numeric), 2)
    ELSE NULL::numeric
END) STORED,
    station_name character varying(200),
    fuel_type character varying(20) DEFAULT 'diesel'::character varying,
    receipt_img character varying(500),
    note text,
    is_approved boolean DEFAULT false,
    approved_by integer,
    approved_at timestamp with time zone,
    created_by integer,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone
);


ALTER TABLE public.fuel_logs OWNER TO postgres;

--
-- Name: fuel_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.fuel_logs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.fuel_logs_id_seq OWNER TO postgres;

--
-- Name: fuel_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.fuel_logs_id_seq OWNED BY public.fuel_logs.id;


--
-- Name: holidays; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.holidays (
    id integer NOT NULL,
    holiday_date date NOT NULL,
    name character varying(200),
    year integer GENERATED ALWAYS AS ((EXTRACT(year FROM holiday_date))::integer) STORED
);


ALTER TABLE public.holidays OWNER TO postgres;

--
-- Name: holidays_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.holidays_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.holidays_id_seq OWNER TO postgres;

--
-- Name: holidays_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.holidays_id_seq OWNED BY public.holidays.id;


--
-- Name: hr_attendance; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.hr_attendance (
    id integer NOT NULL,
    user_id integer NOT NULL,
    work_date date NOT NULL,
    check_in time without time zone,
    check_out time without time zone,
    work_hours numeric(6,2) DEFAULT 0,
    standard_hours numeric(4,2) DEFAULT 8,
    status character varying(20) DEFAULT 'present'::character varying,
    note text,
    entered_by integer,
    created_at timestamp with time zone DEFAULT now(),
    source character varying(20) DEFAULT 'manual'::character varying,
    is_late boolean DEFAULT false,
    late_minutes integer DEFAULT 0,
    updated_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.hr_attendance OWNER TO postgres;

--
-- Name: hr_attendance_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hr_attendance_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hr_attendance_id_seq OWNER TO postgres;

--
-- Name: hr_attendance_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hr_attendance_id_seq OWNED BY public.hr_attendance.id;


--
-- Name: hr_leave_balances; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.hr_leave_balances (
    id integer NOT NULL,
    user_id integer NOT NULL,
    year integer NOT NULL,
    total_days numeric(4,1) DEFAULT 12,
    used_days numeric(4,1) DEFAULT 0,
    remaining_days numeric(4,1) DEFAULT 12
);


ALTER TABLE public.hr_leave_balances OWNER TO postgres;

--
-- Name: hr_leave_balances_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hr_leave_balances_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hr_leave_balances_id_seq OWNER TO postgres;

--
-- Name: hr_leave_balances_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hr_leave_balances_id_seq OWNED BY public.hr_leave_balances.id;


--
-- Name: hr_leaves; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.hr_leaves (
    id integer NOT NULL,
    user_id integer NOT NULL,
    leave_type character varying(30) DEFAULT 'annual'::character varying,
    date_from date NOT NULL,
    date_to date NOT NULL,
    days_count numeric(4,1) NOT NULL,
    reason text,
    status character varying(20) DEFAULT 'pending'::character varying,
    approved_by integer,
    approved_at timestamp with time zone,
    note text,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.hr_leaves OWNER TO postgres;

--
-- Name: hr_leaves_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hr_leaves_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hr_leaves_id_seq OWNER TO postgres;

--
-- Name: hr_leaves_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hr_leaves_id_seq OWNED BY public.hr_leaves.id;


--
-- Name: hr_overtime; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.hr_overtime (
    id integer NOT NULL,
    user_id integer NOT NULL,
    ot_date date NOT NULL,
    ot_hours numeric(4,2) NOT NULL,
    ot_type character varying(20) DEFAULT 'weekday'::character varying,
    ot_rate numeric(4,2) DEFAULT 1.5,
    reason text,
    status character varying(20) DEFAULT 'pending'::character varying,
    approved_by integer,
    approved_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    start_time time without time zone,
    end_time time without time zone,
    note text,
    reject_reason text
);


ALTER TABLE public.hr_overtime OWNER TO postgres;

--
-- Name: hr_overtime_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hr_overtime_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hr_overtime_id_seq OWNER TO postgres;

--
-- Name: hr_overtime_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hr_overtime_id_seq OWNED BY public.hr_overtime.id;


--
-- Name: hr_payroll_items; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.hr_payroll_items (
    id integer NOT NULL,
    period_id integer NOT NULL,
    user_id integer NOT NULL,
    base_salary numeric(15,0) DEFAULT 0,
    allowance numeric(15,0) DEFAULT 0,
    ot_amount numeric(15,0) DEFAULT 0,
    bonus numeric(15,0) DEFAULT 0,
    deduction numeric(15,0) DEFAULT 0,
    gross_salary numeric(15,0) DEFAULT 0,
    tax_amount numeric(15,0) DEFAULT 0,
    insurance numeric(15,0) DEFAULT 0,
    net_salary numeric(15,0) DEFAULT 0,
    work_days integer DEFAULT 0,
    absent_days integer DEFAULT 0,
    ot_hours numeric(6,2) DEFAULT 0,
    note text,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.hr_payroll_items OWNER TO postgres;

--
-- Name: hr_payroll_items_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hr_payroll_items_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hr_payroll_items_id_seq OWNER TO postgres;

--
-- Name: hr_payroll_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hr_payroll_items_id_seq OWNED BY public.hr_payroll_items.id;


--
-- Name: hr_payroll_periods; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.hr_payroll_periods (
    id integer NOT NULL,
    period_year integer NOT NULL,
    period_month integer NOT NULL,
    status character varying(20) DEFAULT 'draft'::character varying,
    locked_by integer,
    locked_at timestamp with time zone,
    total_employees integer DEFAULT 0,
    total_gross numeric(15,0) DEFAULT 0,
    total_net numeric(15,0) DEFAULT 0,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.hr_payroll_periods OWNER TO postgres;

--
-- Name: hr_payroll_periods_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hr_payroll_periods_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hr_payroll_periods_id_seq OWNER TO postgres;

--
-- Name: hr_payroll_periods_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hr_payroll_periods_id_seq OWNED BY public.hr_payroll_periods.id;


--
-- Name: hr_salary_configs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.hr_salary_configs (
    id integer NOT NULL,
    user_id integer NOT NULL,
    base_salary numeric(15,0) DEFAULT 0 NOT NULL,
    allowance numeric(15,0) DEFAULT 0 NOT NULL,
    "position" character varying(100),
    department character varying(100),
    start_date date NOT NULL,
    end_date date,
    is_active boolean DEFAULT true,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.hr_salary_configs OWNER TO postgres;

--
-- Name: hr_salary_configs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hr_salary_configs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hr_salary_configs_id_seq OWNER TO postgres;

--
-- Name: hr_salary_configs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hr_salary_configs_id_seq OWNED BY public.hr_salary_configs.id;


--
-- Name: kpi_config; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.kpi_config (
    id integer NOT NULL,
    name character varying(100) NOT NULL,
    key character varying(50) NOT NULL,
    weight numeric(5,2) DEFAULT 0 NOT NULL,
    target numeric(10,4),
    unit character varying(30),
    description text,
    is_active boolean DEFAULT true,
    updated_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.kpi_config OWNER TO postgres;

--
-- Name: kpi_config_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.kpi_config_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.kpi_config_id_seq OWNER TO postgres;

--
-- Name: kpi_config_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.kpi_config_id_seq OWNED BY public.kpi_config.id;


--
-- Name: kpi_scores; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.kpi_scores (
    id integer NOT NULL,
    driver_id integer NOT NULL,
    period_from date NOT NULL,
    period_to date NOT NULL,
    score_fuel numeric(5,2),
    score_safety numeric(5,2),
    score_vehicle numeric(5,2),
    score_customer numeric(5,2),
    actual_fuel_rate numeric(8,4),
    target_fuel_rate numeric(8,4),
    maintenance_faults integer DEFAULT 0,
    customer_rating numeric(3,1),
    total_km numeric(10,2),
    total_fuel_liters numeric(10,2),
    total_trips integer,
    score_total numeric(5,2),
    grade character(2),
    notes text,
    calculated_by integer,
    calculated_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.kpi_scores OWNER TO postgres;

--
-- Name: kpi_scores_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.kpi_scores_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.kpi_scores_id_seq OWNER TO postgres;

--
-- Name: kpi_scores_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.kpi_scores_id_seq OWNED BY public.kpi_scores.id;


--
-- Name: maintenance_logs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.maintenance_logs (
    id integer NOT NULL,
    vehicle_id integer NOT NULL,
    log_date date DEFAULT CURRENT_DATE NOT NULL,
    maintenance_type character varying(50) DEFAULT 'repair'::character varying,
    description text,
    cost numeric(15,2) DEFAULT 0 NOT NULL,
    garage_name character varying(200),
    invoice_number character varying(100),
    invoice_image character varying(500),
    odometer_km numeric(10,2),
    next_maintenance_km numeric(10,2),
    next_maintenance_date date,
    entered_by integer,
    verified_by integer,
    status character varying(20) DEFAULT 'pending'::character varying,
    note text,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    parts_cost numeric(15,2) DEFAULT 0,
    labor_cost numeric(15,2) DEFAULT 0,
    approved_by integer,
    approved_at timestamp with time zone,
    created_by integer,
    maintenance_date date DEFAULT CURRENT_DATE,
    total_cost numeric(15,2) DEFAULT 0
);


ALTER TABLE public.maintenance_logs OWNER TO postgres;

--
-- Name: maintenance_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.maintenance_logs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.maintenance_logs_id_seq OWNER TO postgres;

--
-- Name: maintenance_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.maintenance_logs_id_seq OWNED BY public.maintenance_logs.id;


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.notifications (
    id integer NOT NULL,
    user_id integer NOT NULL,
    title character varying(300) NOT NULL,
    message text,
    type character varying(50) DEFAULT 'info'::character varying,
    link character varying(500),
    is_read boolean DEFAULT false,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.notifications OWNER TO postgres;

--
-- Name: notifications_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.notifications_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.notifications_id_seq OWNER TO postgres;

--
-- Name: notifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.notifications_id_seq OWNED BY public.notifications.id;


--
-- Name: payroll_periods; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.payroll_periods (
    id integer NOT NULL,
    period_month integer NOT NULL,
    period_year integer NOT NULL,
    status character varying(20) DEFAULT 'draft'::character varying,
    note text,
    created_by integer,
    approved_by integer,
    approved_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.payroll_periods OWNER TO postgres;

--
-- Name: payroll_periods_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.payroll_periods_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.payroll_periods_id_seq OWNER TO postgres;

--
-- Name: payroll_periods_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.payroll_periods_id_seq OWNED BY public.payroll_periods.id;


--
-- Name: payroll_slips; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.payroll_slips (
    id integer NOT NULL,
    period_id integer NOT NULL,
    user_id integer NOT NULL,
    base_salary numeric(15,2) DEFAULT 0,
    trip_bonus numeric(15,2) DEFAULT 0,
    kpi_bonus numeric(15,2) DEFAULT 0,
    other_bonus numeric(15,2) DEFAULT 0,
    deductions numeric(15,2) DEFAULT 0,
    tax numeric(15,2) DEFAULT 0,
    net_salary numeric(15,2) DEFAULT 0,
    note text,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.payroll_slips OWNER TO postgres;

--
-- Name: payroll_slips_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.payroll_slips_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.payroll_slips_id_seq OWNER TO postgres;

--
-- Name: payroll_slips_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.payroll_slips_id_seq OWNED BY public.payroll_slips.id;


--
-- Name: permissions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.permissions (
    id integer NOT NULL,
    module character varying(100) NOT NULL,
    action character varying(50) NOT NULL,
    label character varying(200)
);


ALTER TABLE public.permissions OWNER TO postgres;

--
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.permissions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.permissions_id_seq OWNER TO postgres;

--
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.permissions_id_seq OWNED BY public.permissions.id;


--
-- Name: price_books; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.price_books (
    id integer NOT NULL,
    customer_id integer NOT NULL,
    name character varying(200) NOT NULL,
    valid_from date NOT NULL,
    valid_to date,
    is_active boolean DEFAULT true,
    note text,
    created_by integer,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.price_books OWNER TO postgres;

--
-- Name: price_books_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.price_books_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.price_books_id_seq OWNER TO postgres;

--
-- Name: price_books_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.price_books_id_seq OWNED BY public.price_books.id;


--
-- Name: price_lists; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.price_lists (
    id integer NOT NULL,
    customer_id integer NOT NULL,
    vehicle_type_id integer,
    route_from character varying(300),
    route_to character varying(300),
    trip_type character varying(20) DEFAULT 'one_way'::character varying,
    price numeric(15,2) NOT NULL,
    unit character varying(20) DEFAULT 'trip'::character varying,
    effective_from date NOT NULL,
    effective_to date,
    note text,
    created_by integer,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.price_lists OWNER TO postgres;

--
-- Name: price_lists_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.price_lists_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.price_lists_id_seq OWNER TO postgres;

--
-- Name: price_lists_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.price_lists_id_seq OWNED BY public.price_lists.id;


--
-- Name: price_rules; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.price_rules (
    id integer NOT NULL,
    price_book_id integer NOT NULL,
    vehicle_id integer NOT NULL,
    pricing_mode character varying(20) DEFAULT 'combo'::character varying,
    combo_monthly_price numeric(15,2),
    combo_km_limit numeric(10,2),
    over_km_price numeric(10,2),
    standard_price_per_km numeric(10,2),
    toll_included boolean DEFAULT false,
    holiday_surcharge numeric(5,2) DEFAULT 0,
    sunday_surcharge numeric(5,2) DEFAULT 0,
    waiting_fee_per_hour numeric(10,2) DEFAULT 0,
    note text,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.price_rules OWNER TO postgres;

--
-- Name: price_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.price_rules_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.price_rules_id_seq OWNER TO postgres;

--
-- Name: price_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.price_rules_id_seq OWNED BY public.price_rules.id;


--
-- Name: role_permissions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.role_permissions (
    role_id integer NOT NULL,
    permission_id integer NOT NULL
);


ALTER TABLE public.role_permissions OWNER TO postgres;

--
-- Name: roles; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.roles (
    id integer NOT NULL,
    name character varying(50) NOT NULL,
    label character varying(100) NOT NULL,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.roles OWNER TO postgres;

--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.roles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.roles_id_seq OWNER TO postgres;

--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: salary_components; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.salary_components (
    id integer NOT NULL,
    user_id integer NOT NULL,
    name character varying(200) NOT NULL,
    amount numeric(15,2) DEFAULT 0,
    component_type character varying(20) DEFAULT 'fixed'::character varying,
    is_active boolean DEFAULT true,
    note text,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.salary_components OWNER TO postgres;

--
-- Name: salary_components_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.salary_components_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.salary_components_id_seq OWNER TO postgres;

--
-- Name: salary_components_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.salary_components_id_seq OWNED BY public.salary_components.id;


--
-- Name: statement_items; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.statement_items (
    id integer NOT NULL,
    period_id integer NOT NULL,
    customer_id integer NOT NULL,
    price_book_name character varying(200),
    trip_count integer DEFAULT 0,
    confirmed_count integer DEFAULT 0,
    total_km numeric(10,2) DEFAULT 0,
    total_toll numeric(15,0) DEFAULT 0,
    total_amount numeric(15,0) DEFAULT 0,
    vehicle_count integer DEFAULT 0,
    has_price boolean DEFAULT false,
    detail_json jsonb,
    created_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.statement_items OWNER TO postgres;

--
-- Name: statement_items_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.statement_items_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.statement_items_id_seq OWNER TO postgres;

--
-- Name: statement_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.statement_items_id_seq OWNED BY public.statement_items.id;


--
-- Name: statement_periods; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.statement_periods (
    id integer NOT NULL,
    period_from date NOT NULL,
    period_to date NOT NULL,
    period_label character varying(100),
    status character varying(20) DEFAULT 'draft'::character varying,
    total_amount numeric(15,0) DEFAULT 0,
    total_km numeric(10,0) DEFAULT 0,
    total_trips integer DEFAULT 0,
    customer_count integer DEFAULT 0,
    locked_by integer,
    locked_at timestamp without time zone,
    created_by integer,
    created_at timestamp without time zone DEFAULT now(),
    note text
);


ALTER TABLE public.statement_periods OWNER TO postgres;

--
-- Name: statement_periods_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.statement_periods_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.statement_periods_id_seq OWNER TO postgres;

--
-- Name: statement_periods_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.statement_periods_id_seq OWNED BY public.statement_periods.id;


--
-- Name: trip_attachments; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.trip_attachments (
    id integer NOT NULL,
    trip_id integer NOT NULL,
    file_name character varying(255) NOT NULL,
    file_path character varying(500) NOT NULL,
    file_size integer,
    mime_type character varying(100),
    uploaded_by integer,
    uploaded_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.trip_attachments OWNER TO postgres;

--
-- Name: trip_attachments_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.trip_attachments_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.trip_attachments_id_seq OWNER TO postgres;

--
-- Name: trip_attachments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.trip_attachments_id_seq OWNED BY public.trip_attachments.id;


--
-- Name: trip_code_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.trip_code_seq
    START WITH 1000
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.trip_code_seq OWNER TO postgres;

--
-- Name: trip_templates; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.trip_templates (
    id integer NOT NULL,
    template_name character varying(200) NOT NULL,
    route_from character varying(300) NOT NULL,
    route_to character varying(300) NOT NULL,
    vehicle_type_id integer,
    default_distance numeric(8,2),
    departure_time time without time zone,
    note text,
    is_active boolean DEFAULT true,
    created_by integer,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.trip_templates OWNER TO postgres;

--
-- Name: trip_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.trip_templates_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.trip_templates_id_seq OWNER TO postgres;

--
-- Name: trip_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.trip_templates_id_seq OWNED BY public.trip_templates.id;


--
-- Name: trips; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.trips (
    id integer NOT NULL,
    trip_code character varying(50),
    driver_id integer NOT NULL,
    vehicle_id integer NOT NULL,
    customer_id integer NOT NULL,
    price_list_id integer,
    route_from character varying(300),
    route_to character varying(300),
    trip_date date NOT NULL,
    departure_time time without time zone,
    arrival_time time without time zone,
    distance_km numeric(8,2),
    cargo_description text,
    cargo_weight_ton numeric(8,2),
    passengers integer,
    agreed_price numeric(15,2),
    extra_fee numeric(15,2) DEFAULT 0,
    toll_fee numeric(15,2) DEFAULT 0,
    total_amount numeric(15,2),
    status character varying(30) DEFAULT 'scheduled'::character varying,
    customer_confirmed_at timestamp with time zone,
    customer_confirmed_by integer,
    customer_note text,
    dispatcher_note text,
    created_by integer,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    rejection_reason text,
    rejected_at timestamp with time zone,
    rejected_by integer,
    toll_amount numeric(12,2) DEFAULT 0,
    is_sunday boolean GENERATED ALWAYS AS ((EXTRACT(dow FROM trip_date) = (0)::numeric)) STORED,
    is_holiday boolean DEFAULT false,
    pickup_location character varying(300),
    dropoff_location character varying(300),
    odometer_start numeric(10,2),
    odometer_end numeric(10,2),
    toll_receipt_img character varying(500),
    confirmed_by integer,
    confirmed_at timestamp with time zone,
    approved_by integer,
    approved_at timestamp with time zone,
    note text,
    total_km numeric(10,2) GENERATED ALWAYS AS (
CASE
    WHEN ((odometer_end IS NOT NULL) AND (odometer_start IS NOT NULL)) THEN (odometer_end - odometer_start)
    ELSE NULL::numeric
END) STORED
);


ALTER TABLE public.trips OWNER TO postgres;

--
-- Name: trips_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.trips_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.trips_id_seq OWNER TO postgres;

--
-- Name: trips_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.trips_id_seq OWNED BY public.trips.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.users (
    id integer NOT NULL,
    username character varying(100) NOT NULL,
    password_hash character varying(255) NOT NULL,
    full_name character varying(200) NOT NULL,
    email character varying(200),
    phone character varying(20),
    role_id integer NOT NULL,
    is_active boolean DEFAULT true,
    avatar character varying(500),
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    employee_code character varying(20),
    gender character varying(10),
    marital_status character varying(20),
    date_of_birth date,
    hire_date date,
    ethnicity character varying(50) DEFAULT 'Kinh'::character varying,
    permanent_province character varying(100),
    permanent_district character varying(100),
    permanent_street character varying(200),
    permanent_address character varying(300),
    temp_same_as_permanent boolean DEFAULT false,
    temp_province character varying(100),
    temp_district character varying(100),
    temp_street character varying(200),
    temp_address character varying(300),
    id_number character varying(20),
    id_issue_date date,
    id_issue_place character varying(200),
    social_insurance character varying(20),
    tax_code character varying(20),
    bank_name character varying(100),
    bank_account character varying(50),
    bank_branch character varying(200),
    department_id integer
);


ALTER TABLE public.users OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.users_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_id_seq OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: vehicle_maintenance; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.vehicle_maintenance (
    id integer NOT NULL,
    vehicle_id integer NOT NULL,
    maintenance_date date NOT NULL,
    maintenance_type character varying(100),
    description text,
    cost numeric(15,2) DEFAULT 0,
    mileage integer,
    garage_name character varying(255),
    next_maintenance_date date,
    notes text,
    is_driver_fault boolean DEFAULT false,
    created_by integer,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.vehicle_maintenance OWNER TO postgres;

--
-- Name: vehicle_maintenance_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.vehicle_maintenance_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.vehicle_maintenance_id_seq OWNER TO postgres;

--
-- Name: vehicle_maintenance_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.vehicle_maintenance_id_seq OWNED BY public.vehicle_maintenance.id;


--
-- Name: vehicle_types; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.vehicle_types (
    id integer NOT NULL,
    name character varying(100) NOT NULL,
    description text,
    created_at timestamp with time zone DEFAULT now(),
    is_active boolean DEFAULT true
);


ALTER TABLE public.vehicle_types OWNER TO postgres;

--
-- Name: vehicle_types_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.vehicle_types_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.vehicle_types_id_seq OWNER TO postgres;

--
-- Name: vehicle_types_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.vehicle_types_id_seq OWNED BY public.vehicle_types.id;


--
-- Name: vehicles; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.vehicles (
    id integer NOT NULL,
    plate_number character varying(20) NOT NULL,
    vehicle_type_id integer,
    brand character varying(100),
    model character varying(100),
    year integer,
    capacity_ton numeric(8,2),
    capacity_seat integer,
    fuel_type character varying(20) DEFAULT 'diesel'::character varying,
    status character varying(20) DEFAULT 'active'::character varying,
    note text,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    fuel_quota numeric(5,2),
    capacity numeric(8,2),
    registration_expiry date,
    insurance_expiry date,
    road_tax_expiry date,
    fire_insurance_expiry date,
    created_by integer,
    updated_by integer,
    is_active boolean DEFAULT true
);


ALTER TABLE public.vehicles OWNER TO postgres;

--
-- Name: vehicles_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.vehicles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.vehicles_id_seq OWNER TO postgres;

--
-- Name: vehicles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.vehicles_id_seq OWNED BY public.vehicles.id;


--
-- Name: work_shifts; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.work_shifts (
    id integer NOT NULL,
    shift_code character varying(20) NOT NULL,
    shift_name character varying(100) NOT NULL,
    start_time time without time zone NOT NULL,
    end_time time without time zone NOT NULL,
    late_threshold integer DEFAULT 15,
    break_minutes integer DEFAULT 60,
    work_hours numeric(4,2) DEFAULT 8,
    ot_multiplier numeric(3,2) DEFAULT 1.5,
    weekend_multiplier numeric(3,2) DEFAULT 2.0,
    holiday_multiplier numeric(3,2) DEFAULT 3.0,
    color character varying(20) DEFAULT '#0d6efd'::character varying,
    is_active boolean DEFAULT true,
    created_by integer,
    created_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.work_shifts OWNER TO postgres;

--
-- Name: work_shifts_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.work_shifts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.work_shifts_id_seq OWNER TO postgres;

--
-- Name: work_shifts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.work_shifts_id_seq OWNED BY public.work_shifts.id;


--
-- Name: customer_statements id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_statements ALTER COLUMN id SET DEFAULT nextval('public.customer_statements_id_seq'::regclass);


--
-- Name: customer_users id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_users ALTER COLUMN id SET DEFAULT nextval('public.customer_users_id_seq'::regclass);


--
-- Name: customers id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customers ALTER COLUMN id SET DEFAULT nextval('public.customers_id_seq'::regclass);


--
-- Name: departments id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.departments ALTER COLUMN id SET DEFAULT nextval('public.departments_id_seq'::regclass);


--
-- Name: driver_kpi id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.driver_kpi ALTER COLUMN id SET DEFAULT nextval('public.driver_kpi_id_seq'::regclass);


--
-- Name: driver_ratings id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.driver_ratings ALTER COLUMN id SET DEFAULT nextval('public.driver_ratings_id_seq'::regclass);


--
-- Name: drivers id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.drivers ALTER COLUMN id SET DEFAULT nextval('public.drivers_id_seq'::regclass);


--
-- Name: employee_shifts id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_shifts ALTER COLUMN id SET DEFAULT nextval('public.employee_shifts_id_seq'::regclass);


--
-- Name: fuel_logs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_logs ALTER COLUMN id SET DEFAULT nextval('public.fuel_logs_id_seq'::regclass);


--
-- Name: holidays id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.holidays ALTER COLUMN id SET DEFAULT nextval('public.holidays_id_seq'::regclass);


--
-- Name: hr_attendance id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_attendance ALTER COLUMN id SET DEFAULT nextval('public.hr_attendance_id_seq'::regclass);


--
-- Name: hr_leave_balances id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_leave_balances ALTER COLUMN id SET DEFAULT nextval('public.hr_leave_balances_id_seq'::regclass);


--
-- Name: hr_leaves id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_leaves ALTER COLUMN id SET DEFAULT nextval('public.hr_leaves_id_seq'::regclass);


--
-- Name: hr_overtime id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_overtime ALTER COLUMN id SET DEFAULT nextval('public.hr_overtime_id_seq'::regclass);


--
-- Name: hr_payroll_items id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_payroll_items ALTER COLUMN id SET DEFAULT nextval('public.hr_payroll_items_id_seq'::regclass);


--
-- Name: hr_payroll_periods id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_payroll_periods ALTER COLUMN id SET DEFAULT nextval('public.hr_payroll_periods_id_seq'::regclass);


--
-- Name: hr_salary_configs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_salary_configs ALTER COLUMN id SET DEFAULT nextval('public.hr_salary_configs_id_seq'::regclass);


--
-- Name: kpi_config id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.kpi_config ALTER COLUMN id SET DEFAULT nextval('public.kpi_config_id_seq'::regclass);


--
-- Name: kpi_scores id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.kpi_scores ALTER COLUMN id SET DEFAULT nextval('public.kpi_scores_id_seq'::regclass);


--
-- Name: maintenance_logs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.maintenance_logs ALTER COLUMN id SET DEFAULT nextval('public.maintenance_logs_id_seq'::regclass);


--
-- Name: notifications id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notifications ALTER COLUMN id SET DEFAULT nextval('public.notifications_id_seq'::regclass);


--
-- Name: payroll_periods id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.payroll_periods ALTER COLUMN id SET DEFAULT nextval('public.payroll_periods_id_seq'::regclass);


--
-- Name: payroll_slips id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.payroll_slips ALTER COLUMN id SET DEFAULT nextval('public.payroll_slips_id_seq'::regclass);


--
-- Name: permissions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.permissions ALTER COLUMN id SET DEFAULT nextval('public.permissions_id_seq'::regclass);


--
-- Name: price_books id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_books ALTER COLUMN id SET DEFAULT nextval('public.price_books_id_seq'::regclass);


--
-- Name: price_lists id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_lists ALTER COLUMN id SET DEFAULT nextval('public.price_lists_id_seq'::regclass);


--
-- Name: price_rules id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_rules ALTER COLUMN id SET DEFAULT nextval('public.price_rules_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: salary_components id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.salary_components ALTER COLUMN id SET DEFAULT nextval('public.salary_components_id_seq'::regclass);


--
-- Name: statement_items id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.statement_items ALTER COLUMN id SET DEFAULT nextval('public.statement_items_id_seq'::regclass);


--
-- Name: statement_periods id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.statement_periods ALTER COLUMN id SET DEFAULT nextval('public.statement_periods_id_seq'::regclass);


--
-- Name: trip_attachments id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trip_attachments ALTER COLUMN id SET DEFAULT nextval('public.trip_attachments_id_seq'::regclass);


--
-- Name: trip_templates id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trip_templates ALTER COLUMN id SET DEFAULT nextval('public.trip_templates_id_seq'::regclass);


--
-- Name: trips id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trips ALTER COLUMN id SET DEFAULT nextval('public.trips_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: vehicle_maintenance id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_maintenance ALTER COLUMN id SET DEFAULT nextval('public.vehicle_maintenance_id_seq'::regclass);


--
-- Name: vehicle_types id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_types ALTER COLUMN id SET DEFAULT nextval('public.vehicle_types_id_seq'::regclass);


--
-- Name: vehicles id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicles ALTER COLUMN id SET DEFAULT nextval('public.vehicles_id_seq'::regclass);


--
-- Name: work_shifts id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.work_shifts ALTER COLUMN id SET DEFAULT nextval('public.work_shifts_id_seq'::regclass);


--
-- Data for Name: customer_statements; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.customer_statements (id, customer_id, statement_type, period_from, period_to, total_trips, total_amount, paid_amount, debt_amount, status, sent_at, confirmed_at, confirmed_by, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: customer_users; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.customer_users (id, customer_id, user_id, role, is_primary, is_active, created_at, updated_at) FROM stdin;
6	1	8	approver	f	t	2026-03-21 18:40:33.70736+07	\N
7	2	9	approver	f	t	2026-03-21 19:04:41.262703+07	\N
8	2	5	approver	f	t	2026-03-22 20:22:12.465907+07	\N
\.


--
-- Data for Name: customers; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.customers (id, customer_code, company_name, short_name, tax_code, legal_address, invoice_address, legal_representative, representative_title, primary_contact_name, primary_contact_phone, primary_contact_email, bank_name, bank_account_number, bank_branch, payment_terms, billing_cycle, billing_day, is_active, note, created_by, updated_by, created_at, updated_at) FROM stdin;
1	KH001	CÔNG TY TNHH SCAN GLOBAL LOGISTICS VIỆT NAM	SCAN	0311824673	CÔNG TY TNHH SCAN GLOBAL LOGISTICS VIỆT NAM	CÔNG TY TNHH SCAN GLOBAL LOGISTICS VIỆT NAM	Dung	Giám Đóc	DŨNG ĐÀO NGỌC ANH	\N	dung@dnaexpress.vn	\N	\N	\N	30	monthly	\N	t	\N	1	\N	2026-03-21 16:46:39.411269+07	\N
2	KH002	CHI NHÁNH HÀ NỘI - CÔNG TY CỔ PHẦN 25 FIT	25 FIT	3703140969	CHI NHÁNH HÀ NỘI - CÔNG TY CỔ PHẦN 25 FIT	CHI NHÁNH HÀ NỘI - CÔNG TY CỔ PHẦN 25 FIT	\N	\N	\N	\N	\N	\N	\N	\N	30	monthly	25	t	\N	2	\N	2026-03-21 19:04:10.822989+07	\N
\.


--
-- Data for Name: departments; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.departments (id, name, code, manager_id, is_active, created_at) FROM stdin;
1	Ban Giám đốc	BGD	\N	t	2026-03-22 20:05:51.74202
2	Kế toán - Tài chính	KT	\N	t	2026-03-22 20:05:51.74202
3	Điều hành xe	DHX	\N	t	2026-03-22 20:05:51.74202
4	Lái xe	LX	\N	t	2026-03-22 20:05:51.74202
5	Hành chính	HC	\N	t	2026-03-22 20:05:51.74202
\.


--
-- Data for Name: driver_kpi; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.driver_kpi (id, driver_id, period_month, period_year, total_trips, total_km, total_revenue, kpi_score, kpi_target, bonus_amount, penalty_amount, note, calculated_by, calculated_at, created_at) FROM stdin;
\.


--
-- Data for Name: driver_ratings; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.driver_ratings (id, driver_id, trip_id, customer_id, rating, comment, rated_by, rated_at, is_complaint, rating_punctual, rating_attitude, rating_cargo, rating_vehicle) FROM stdin;
\.


--
-- Data for Name: drivers; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.drivers (id, user_id, license_number, license_class, license_expiry, hire_date, base_salary, kpi_target, is_active, created_by, created_at, updated_at) FROM stdin;
1	3	\N	\N	\N	2026-03-21	0.00	100.00	t	\N	2026-03-21 15:42:15.522163+07	2026-03-21 15:42:15.522163+07
\.


--
-- Data for Name: employee_shifts; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.employee_shifts (id, user_id, shift_id, effective_date, end_date, created_by, created_at) FROM stdin;
1	2	1	2026-03-22	\N	2	2026-03-22 20:14:42.941161
2	3	1	2026-03-22	\N	2	2026-03-22 20:14:42.945876
3	1	1	2026-03-22	\N	2	2026-03-22 20:14:42.946575
\.


--
-- Data for Name: fuel_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.fuel_logs (id, driver_id, vehicle_id, log_date, km_before, km_after, liters_filled, amount, station_name, fuel_type, receipt_img, note, is_approved, approved_by, approved_at, created_by, created_at, updated_at) FROM stdin;
1	1	5	2026-03-21	1110.00	1320.00	50.00	1200000.00	\N	diesel	\N	\N	t	2	2026-03-21 19:54:32.631291+07	3	2026-03-21 19:10:58.248333+07	\N
2	1	5	2026-03-21	1320.00	2131.00	55.50	1231345.00	\N	diesel	/transport/uploads/fuel_receipts/fuel_1_1774097564.png	\N	t	2	2026-03-21 19:54:34.433908+07	3	2026-03-21 19:52:44.314671+07	\N
3	1	1	2026-03-22	2123.00	21313.00	231.00	2313332.00	\N	diesel	/transport/uploads/fuel_receipts/fuel_1_1774192064.png		t	10	2026-03-22 22:18:44.75746+07	3	2026-03-22 22:07:44.009938+07	2026-03-22 22:19:04.977204+07
\.


--
-- Data for Name: holidays; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.holidays (id, holiday_date, name) FROM stdin;
1	2026-01-01	Tết Dương lịch
2	2026-02-17	Tết Nguyên Đán (30 Tết)
3	2026-02-18	Tết Nguyên Đán (Mùng 1)
4	2026-02-19	Tết Nguyên Đán (Mùng 2)
5	2026-02-20	Tết Nguyên Đán (Mùng 3)
6	2026-02-21	Tết Nguyên Đán (Mùng 4)
7	2026-02-22	Tết Nguyên Đán (Mùng 5)
8	2026-04-30	Giải phóng miền Nam
9	2026-05-01	Quốc tế Lao động
10	2026-09-02	Quốc khánh
\.


--
-- Data for Name: hr_attendance; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hr_attendance (id, user_id, work_date, check_in, check_out, work_hours, standard_hours, status, note, entered_by, created_at, source, is_late, late_minutes, updated_at) FROM stdin;
1	2	2026-03-22	12:10:37	12:16:22	0.10	8.00	present	\N	\N	2026-03-22 19:10:37.688166+07	manual	f	0	2026-03-22 19:23:34.737339
2	3	2026-03-22	12:10:55	12:20:46	0.16	8.00	present	\N	\N	2026-03-22 19:10:55.820106+07	manual	f	0	2026-03-22 19:23:34.737339
3	2	2026-03-06	06:19:00	19:20:00	13.02	8.00	present		\N	2026-03-22 21:19:11.67711+07	manual	f	0	2026-03-22 21:19:20.791576
5	2	2026-03-21	09:00:00	18:02:00	9.03	8.00	present		\N	2026-03-22 21:19:41.3753+07	manual	f	0	2026-03-22 21:19:41.3753
\.


--
-- Data for Name: hr_leave_balances; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hr_leave_balances (id, user_id, year, total_days, used_days, remaining_days) FROM stdin;
\.


--
-- Data for Name: hr_leaves; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hr_leaves (id, user_id, leave_type, date_from, date_to, days_count, reason, status, approved_by, approved_at, note, created_at, updated_at) FROM stdin;
1	2	annual	2026-03-23	2026-03-23	1.0	2	approved	2	2026-03-22 19:21:57.378313+07	\N	2026-03-22 19:21:53.632084+07	2026-03-22 19:21:57.378313
2	3	annual	2026-03-23	2026-03-23	1.0	nghỉ ốm	approved	2	2026-03-22 19:42:11.961945+07	\N	2026-03-22 19:42:04.309304+07	2026-03-22 19:42:11.961945
\.


--
-- Data for Name: hr_overtime; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hr_overtime (id, user_id, ot_date, ot_hours, ot_type, ot_rate, reason, status, approved_by, approved_at, created_at, updated_at, start_time, end_time, note, reject_reason) FROM stdin;
1	2	2026-03-22	2.00	weekday	1.50	1	approved	2	2026-03-22 19:21:40.721838+07	2026-03-22 19:19:25.176104+07	2026-03-22 19:21:40.721838+07	\N	\N	\N	\N
2	2	2026-03-23	2.00	weekday	1.50	1	approved	2	2026-03-22 19:34:29.016292+07	2026-03-22 19:34:19.890973+07	2026-03-22 19:34:29.016292+07	\N	\N	\N	\N
3	3	2026-03-22	3.00	weekend	1.50	1	approved	2	2026-03-22 19:41:22.337897+07	2026-03-22 19:41:13.310672+07	2026-03-22 19:41:22.337897+07	17:00:00	20:00:00	\N	\N
\.


--
-- Data for Name: hr_payroll_items; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hr_payroll_items (id, period_id, user_id, base_salary, allowance, ot_amount, bonus, deduction, gross_salary, tax_amount, insurance, net_salary, work_days, absent_days, ot_hours, note, created_at) FROM stdin;
\.


--
-- Data for Name: hr_payroll_periods; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hr_payroll_periods (id, period_year, period_month, status, locked_by, locked_at, total_employees, total_gross, total_net, created_at) FROM stdin;
\.


--
-- Data for Name: hr_salary_configs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hr_salary_configs (id, user_id, base_salary, allowance, "position", department, start_date, end_date, is_active, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: kpi_config; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.kpi_config (id, name, key, weight, target, unit, description, is_active, updated_at) FROM stdin;
1	Hiệu suất nhiên liệu	fuel	40.00	8.5000	L/100km	Định mức ≤ 8.5 L/100km, càng thấp càng tốt	t	2026-03-21 20:30:37.339903+07
2	An toàn / Lỗi chủ quan	safety	40.00	0.0000	lần	Số lần hư hỏng do lỗi chủ quan, mục tiêu = 0	t	2026-03-21 20:30:37.339903+07
3	Bảo quản xe	vehicle	10.00	0.0000	lần	Số lần vi phạm bảo dưỡng định kỳ	t	2026-03-21 20:30:37.339903+07
4	Đánh giá khách hàng	customer	10.00	4.5000	điểm/5	Điểm trung bình KH chấm, mục tiêu ≥ 4.5/5	t	2026-03-21 20:30:37.339903+07
\.


--
-- Data for Name: kpi_scores; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.kpi_scores (id, driver_id, period_from, period_to, score_fuel, score_safety, score_vehicle, score_customer, actual_fuel_rate, target_fuel_rate, maintenance_faults, customer_rating, total_km, total_fuel_liters, total_trips, score_total, grade, notes, calculated_by, calculated_at) FROM stdin;
1	1	2026-02-26	2026-03-25	100.00	100.00	100.00	80.00	10.3330	12.0000	0	\N	102000.00	105.50	2	98.00	A+	\N	2	2026-03-21 20:40:13.650033+07
\.


--
-- Data for Name: maintenance_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.maintenance_logs (id, vehicle_id, log_date, maintenance_type, description, cost, garage_name, invoice_number, invoice_image, odometer_km, next_maintenance_km, next_maintenance_date, entered_by, verified_by, status, note, created_at, updated_at, parts_cost, labor_cost, approved_by, approved_at, created_by, maintenance_date, total_cost) FROM stdin;
1	5	2026-03-22	scheduled	Bảo Dưỡng	0.00	KIA	\N	\N	20000.00	30000.00	\N	\N	\N	completed	\N	2026-03-22 14:40:07.733827+07	2026-03-22 14:40:07.733827+07	1200000.00	0.00	2	2026-03-22 14:40:25.78945+07	2	2026-03-22	1200000.00
2	5	2026-03-22	scheduled	bảo dưỡng	0.00	KIA	\N	\N	\N	\N	\N	\N	\N	completed	\N	2026-03-22 16:06:21.756789+07	2026-03-22 16:06:21.756789+07	0.00	0.00	\N	\N	2	2026-03-22	0.00
3	1	2026-03-22	repair	121	0.00		\N	\N	\N	\N	\N	\N	\N	completed		2026-03-22 16:06:47.072124+07	2026-03-22 22:23:45.429655+07	500000.00	50000000.00	\N	\N	2	2026-03-22	50500000.00
\.


--
-- Data for Name: notifications; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.notifications (id, user_id, title, message, type, link, is_read, created_at) FROM stdin;
1	1	📋 Đơn đăng ký OT mới	DŨNG ĐÀO NGỌC ANH đăng ký OT ngày 22/03/2026 (2 giờ)	ot_request	/transport/hr/modules/overtime/manage.php	f	2026-03-22 19:19:25.179718+07
2	1	📋 Đơn xin nghỉ phép mới	DŨNG ĐÀO NGỌC ANH xin nghỉ từ 23/03/2026 đến 23/03/2026 (1 ngày)	leave_request	/transport/hr/modules/leave/manage.php	f	2026-03-22 19:21:53.635756+07
3	1	📋 Đơn đăng ký OT mới	DŨNG ĐÀO NGỌC ANH đăng ký OT ngày 23/03/2026 (2 giờ)	ot_request	/transport/hr/modules/overtime/manage.php	f	2026-03-22 19:34:19.894003+07
4	1	📋 Đơn OT mới từ lái xe	NGuyễn Văn lái đăng ký OT ngày 22/03/2026 (17:00–20:00, 3 giờ)	ot_request	\N	f	2026-03-22 19:41:13.314014+07
5	1	📋 Đơn nghỉ phép từ lái xe	NGuyễn Văn lái xin nghỉ 1 ngày (23/03 – 23/03/2026)	leave_request	\N	f	2026-03-22 19:42:04.312805+07
\.


--
-- Data for Name: payroll_periods; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.payroll_periods (id, period_month, period_year, status, note, created_by, approved_by, approved_at, created_at) FROM stdin;
\.


--
-- Data for Name: payroll_slips; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.payroll_slips (id, period_id, user_id, base_salary, trip_bonus, kpi_bonus, other_bonus, deductions, tax, net_salary, note, created_at) FROM stdin;
\.


--
-- Data for Name: permissions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.permissions (id, module, action, label) FROM stdin;
1	dashboard	view_full	Xem dashboard đầy đủ
2	dashboard	view_accountant	Xem dashboard kế toán
3	dashboard	view_dispatcher	Xem dashboard vận hành
4	users	view	Xem danh sách users
5	users	create	Tạo user
6	users	edit	Sửa user
7	users	delete	Xóa user
8	users	assign_role	Phân quyền
9	payroll	approve	Duyệt lương
10	payroll	submit	Đẩy lương lên duyệt
11	payroll	view	Xem bảng lương
12	customers	crud	CRUD khách hàng
13	customers	view	Xem khách hàng
14	pricebook	crud	CRUD bảng giá
15	pricebook	view	Xem bảng giá
16	drivers	crud	CRUD lái xe
17	drivers	view	Xem lái xe (không thấy lương)
18	vehicles	crud	CRUD xe
19	vehicles	view	Xem xe
20	trips	view_all	Xem tất cả chuyến
21	trips	view_own	Xem chuyến của mình
22	trips	view_company	Xem chuyến của công ty
23	trips	create	Tạo chuyến xe
24	trips	edit	Sửa chuyến xe
25	trips	delete	Xóa chuyến xe
26	trips	view_price	Xem giá/doanh thu trong chuyến
27	trips	confirm	Confirm/Reject chuyến
28	trips	override	Override confirm
29	kpi	view	Xem KPI
30	kpi	manage	Quản lý KPI
31	kpi	calculate	Tính KPI/Thưởng
32	expenses	view	Xem chi phí
33	expenses	create	Nhập chi phí
34	expenses	approve	Duyệt chi phí
35	fuel	create	Nhập xăng dầu
36	statements	crud	CRUD + chốt bảng kê
37	statements	view_own	Xem + in bảng kê của mình
38	reports	view_full	Xem báo cáo đầy đủ
39	reports	view_operations	Xem báo cáo vận hành
40	reports	view_own	Xem báo cáo của mình
41	fuel	view_all	Quản lý xăng dầu
42	reports	view	Xem báo cáo
43	hr	view	Xem HR Dashboard
44	hr	manage	Quản lý HR
45	hr	payroll	Xem/Tính bảng lương
46	hr	attendance	Chấm công
47	hr	approve	Duyệt phép / OT
\.


--
-- Data for Name: price_books; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.price_books (id, customer_id, name, valid_from, valid_to, is_active, note, created_by, created_at) FROM stdin;
1	1	Q2	2026-03-21	\N	t	\N	1	2026-03-21 16:49:06.940872+07
2	2	Q2	2026-03-21	\N	t	\N	2	2026-03-21 21:38:06.735562+07
\.


--
-- Data for Name: price_lists; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.price_lists (id, customer_id, vehicle_type_id, route_from, route_to, trip_type, price, unit, effective_from, effective_to, note, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: price_rules; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.price_rules (id, price_book_id, vehicle_id, pricing_mode, combo_monthly_price, combo_km_limit, over_km_price, standard_price_per_km, toll_included, holiday_surcharge, sunday_surcharge, waiting_fee_per_hour, note, created_at) FROM stdin;
1	1	5	combo	40000000.00	3000.00	10000.00	\N	f	0.00	0.00	0.00	\N	2026-03-21 16:49:32.217729+07
2	1	1	standard	\N	\N	\N	10000.00	f	20.00	10.00	0.00	\N	2026-03-21 18:51:19.889135+07
3	2	5	standard	\N	\N	\N	15000.00	f	0.00	0.00	0.00	\N	2026-03-21 21:38:17.028145+07
4	2	1	standard	\N	\N	\N	10000.00	f	0.00	0.00	0.00	\N	2026-03-21 21:38:25.610689+07
\.


--
-- Data for Name: role_permissions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.role_permissions (role_id, permission_id) FROM stdin;
2	47
2	46
2	44
2	45
2	43
1	12
1	13
1	2
1	3
1	1
1	16
1	17
1	34
1	33
1	32
1	35
6	22
6	27
6	37
6	40
1	41
1	47
1	46
1	44
1	45
1	43
1	31
1	30
1	29
4	13
4	3
4	16
4	17
4	33
4	32
4	35
4	41
4	47
4	46
4	43
4	30
4	29
4	42
4	38
4	39
4	40
4	27
4	23
4	25
4	24
4	28
4	20
4	22
4	21
4	26
4	18
3	12
3	2
3	17
3	34
2	1
2	2
2	3
2	4
2	5
2	6
2	7
2	8
2	9
2	10
2	11
2	12
2	13
2	14
2	15
2	16
2	17
2	18
2	19
2	20
2	21
2	22
2	23
2	24
2	25
2	26
2	27
2	28
2	29
2	30
2	31
2	32
2	33
2	34
2	35
2	36
2	37
2	38
2	39
2	40
3	41
3	47
2	41
1	9
1	10
1	11
1	14
1	15
1	42
3	46
2	42
1	38
3	44
3	45
3	43
3	31
3	10
3	11
3	14
3	15
3	42
3	38
3	39
3	36
3	37
3	27
3	24
3	20
3	26
3	8
3	5
3	7
3	6
3	4
3	18
3	19
1	39
1	40
1	36
1	37
1	27
1	23
1	25
1	24
1	28
1	20
1	22
1	21
1	26
1	8
4	44
5	33
5	32
5	35
5	41
5	43
5	23
5	24
5	21
1	5
1	7
1	6
1	4
1	18
1	19
\.


--
-- Data for Name: roles; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.roles (id, name, label, created_at) FROM stdin;
1	superadmin	Ban Điều Hành	2026-03-21 15:34:01.075511+07
2	admin	Quản Trị Hệ Thống	2026-03-21 15:34:01.075511+07
3	accountant	Kế Toán	2026-03-21 15:34:01.075511+07
4	dispatcher	Điều Hành Xe	2026-03-21 15:34:01.075511+07
5	driver	Lái Xe	2026-03-21 15:34:01.075511+07
6	customer	Khách Hàng	2026-03-21 15:34:01.075511+07
\.


--
-- Data for Name: salary_components; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.salary_components (id, user_id, name, amount, component_type, is_active, note, created_at) FROM stdin;
\.


--
-- Data for Name: statement_items; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.statement_items (id, period_id, customer_id, price_book_name, trip_count, confirmed_count, total_km, total_toll, total_amount, vehicle_count, has_price, detail_json, created_at) FROM stdin;
9	1	2	Q2	4	4	103431.00	0	1046465000	2	t	{"pb_name": "Q2", "tax_code": "3703140969", "total_km": 103431, "vehicles": {"30H21778": {"trips": [{"id": 4, "note": null, "pb_id": 2, "status": "confirmed", "cust_id": 2, "pb_name": "Q2", "rule_id": 3, "capacity": "5.00", "route_to": null, "tax_code": "3703140969", "toll_fee": null, "total_km": "1090.00", "bank_name": null, "driver_id": 1, "extra_fee": "0.00", "is_sunday": false, "trip_code": "CH26030004", "trip_date": "2026-03-21", "created_at": "2026-03-21 20:52:15.15034+07", "created_by": 2, "is_holiday": false, "passengers": null, "route_from": null, "short_name": "25 FIT", "updated_at": "2026-03-21 20:52:30.450726+07", "vehicle_id": 5, "approved_at": "2026-03-21 20:52:18.107496+07", "approved_by": 2, "customer_id": 2, "distance_km": null, "driver_name": "NGuyễn Văn lái", "rejected_at": null, "rejected_by": null, "toll_amount": "0.00", "agreed_price": null, "arrival_time": null, "company_name": "CHI NHÁNH HÀ NỘI - CÔNG TY CỔ PHẦN 25 FIT", "confirmed_at": "2026-03-21 20:52:30.450726+07", "confirmed_by": 9, "odometer_end": "3221.00", "plate_number": "30H21778", "pricing_mode": "standard", "total_amount": null, "customer_code": "KH002", "customer_note": null, "over_km_price": null, "price_list_id": null, "toll_included": false, "combo_km_limit": null, "departure_time": null, "odometer_start": "2131.00", "dispatcher_note": null, "pickup_location": "ILSUNG", "cargo_weight_ton": null, "dropoff_location": "ECOTECH", "rejection_reason": null, "sunday_surcharge": "0.00", "toll_receipt_img": null, "cargo_description": null, "holiday_surcharge": "0.00", "bank_account_number": null, "combo_monthly_price": null, "primary_contact_name": null, "waiting_fee_per_hour": "0.00", "customer_confirmed_at": null, "customer_confirmed_by": null, "primary_contact_phone": null, "standard_price_per_km": "15000.00"}, {"id": 3, "note": null, "pb_id": 2, "status": "confirmed", "cust_id": 2, "pb_name": "Q2", "rule_id": 3, "capacity": "5.00", "route_to": null, "tax_code": "3703140969", "toll_fee": null, "total_km": "1021.00", "bank_name": null, "driver_id": 1, "extra_fee": "0.00", "is_sunday": false, "trip_code": "CH26030003", "trip_date": "2026-03-21", "created_at": "2026-03-21 20:45:45.516268+07", "created_by": 2, "is_holiday": false, "passengers": null, "route_from": null, "short_name": "25 FIT", "updated_at": "2026-03-21 20:47:07.871422+07", "vehicle_id": 5, "approved_at": "2026-03-21 20:46:07.461768+07", "approved_by": 2, "customer_id": 2, "distance_km": null, "driver_name": "NGuyễn Văn lái", "rejected_at": null, "rejected_by": null, "toll_amount": "0.00", "agreed_price": null, "arrival_time": null, "company_name": "CHI NHÁNH HÀ NỘI - CÔNG TY CỔ PHẦN 25 FIT", "confirmed_at": "2026-03-21 20:47:07.871422+07", "confirmed_by": 9, "odometer_end": "2131.00", "plate_number": "30H21778", "pricing_mode": "standard", "total_amount": null, "customer_code": "KH002", "customer_note": null, "over_km_price": null, "price_list_id": null, "toll_included": false, "combo_km_limit": null, "departure_time": null, "odometer_start": "1110.00", "dispatcher_note": null, "pickup_location": "ILSUNG", "cargo_weight_ton": null, "dropoff_location": "ECOTECH", "rejection_reason": null, "sunday_surcharge": "0.00", "toll_receipt_img": null, "cargo_description": null, "holiday_surcharge": "0.00", "bank_account_number": null, "combo_monthly_price": null, "primary_contact_name": null, "waiting_fee_per_hour": "0.00", "customer_confirmed_at": null, "customer_confirmed_by": null, "primary_contact_phone": null, "standard_price_per_km": "15000.00"}, {"id": 5, "note": "", "pb_id": 2, "status": "confirmed", "cust_id": 2, "pb_name": "Q2", "rule_id": 3, "capacity": "5.00", "route_to": null, "tax_code": "3703140969", "toll_fee": "0.00", "total_km": "320.00", "bank_name": null, "driver_id": 1, "extra_fee": "0.00", "is_sunday": true, "trip_code": "CH26030005", "trip_date": "2026-03-22", "created_at": "2026-03-22 17:35:06.364921+07", "created_by": 3, "is_holiday": false, "passengers": null, "route_from": null, "short_name": "25 FIT", "updated_at": "2026-03-22 21:59:15.941359+07", "vehicle_id": 5, "approved_at": "2026-03-22 21:58:58.213473+07", "approved_by": 2, "customer_id": 2, "distance_km": null, "driver_name": "NGuyễn Văn lái", "rejected_at": "2026-03-22 19:07:41.613401+07", "rejected_by": 9, "toll_amount": "0.00", "agreed_price": null, "arrival_time": null, "company_name": "CHI NHÁNH HÀ NỘI - CÔNG TY CỔ PHẦN 25 FIT", "confirmed_at": "2026-03-22 21:59:15.941359+07", "confirmed_by": 9, "odometer_end": "3544.00", "plate_number": "30H21778", "pricing_mode": "standard", "total_amount": null, "customer_code": "KH002", "customer_note": null, "over_km_price": null, "price_list_id": null, "toll_included": false, "combo_km_limit": null, "departure_time": null, "odometer_start": "3224.00", "dispatcher_note": null, "pickup_location": "ILSUNG", "cargo_weight_ton": null, "dropoff_location": "ECOTECH", "rejection_reason": "chưa chạy đến nơi", "sunday_surcharge": "0.00", "toll_receipt_img": null, "cargo_description": null, "holiday_surcharge": "0.00", "bank_account_number": null, "combo_monthly_price": null, "primary_contact_name": null, "waiting_fee_per_hour": "0.00", "customer_confirmed_at": null, "customer_confirmed_by": null, "primary_contact_phone": null, "standard_price_per_km": "15000.00"}], "over_km": 0, "capacity": "5.00", "has_rule": true, "total_km": 2431, "sunday_km": 320, "total_toll": 0, "trip_count": 3, "amount_base": 36465000, "amount_toll": 0, "over_amount": 0, "amount_total": 36465000, "plate_number": "30H21778", "pricing_mode": "standard", "sunday_trips": 1, "over_km_price": 0, "toll_included": false, "combo_km_limit": 0, "amount_surcharge": 0, "sunday_surcharge": "0.00", "holiday_surcharge": "0.00", "combo_monthly_price": 0, "standard_price_per_km": "15000.00"}, "51C-123.45": {"trips": [{"id": 2, "note": null, "pb_id": 2, "status": "confirmed", "cust_id": 2, "pb_name": "Q2", "rule_id": 4, "capacity": null, "route_to": null, "tax_code": "3703140969", "toll_fee": null, "total_km": "101000.00", "bank_name": null, "driver_id": 1, "extra_fee": "0.00", "is_sunday": false, "trip_code": "CH26030002", "trip_date": "2026-03-21", "created_at": "2026-03-21 19:10:12.787446+07", "created_by": 3, "is_holiday": false, "passengers": null, "route_from": null, "short_name": "25 FIT", "updated_at": "2026-03-21 19:55:48.065395+07", "vehicle_id": 1, "approved_at": "2026-03-21 19:11:27.445758+07", "approved_by": 2, "customer_id": 2, "distance_km": null, "driver_name": "NGuyễn Văn lái", "rejected_at": null, "rejected_by": null, "toll_amount": "0.00", "agreed_price": null, "arrival_time": null, "company_name": "CHI NHÁNH HÀ NỘI - CÔNG TY CỔ PHẦN 25 FIT", "confirmed_at": "2026-03-21 19:55:48.065395+07", "confirmed_by": 9, "odometer_end": "112000.00", "plate_number": "51C-123.45", "pricing_mode": "standard", "total_amount": null, "customer_code": "KH002", "customer_note": null, "over_km_price": null, "price_list_id": null, "toll_included": false, "combo_km_limit": null, "departure_time": null, "odometer_start": "11000.00", "dispatcher_note": null, "pickup_location": "ILSUNG", "cargo_weight_ton": null, "dropoff_location": "ECOTECH", "rejection_reason": null, "sunday_surcharge": "0.00", "toll_receipt_img": null, "cargo_description": null, "holiday_surcharge": "0.00", "bank_account_number": null, "combo_monthly_price": null, "primary_contact_name": null, "waiting_fee_per_hour": "0.00", "customer_confirmed_at": null, "customer_confirmed_by": null, "primary_contact_phone": null, "standard_price_per_km": "10000.00"}], "over_km": 0, "capacity": "", "has_rule": true, "total_km": 101000, "sunday_km": 0, "total_toll": 0, "trip_count": 1, "amount_base": 1010000000, "amount_toll": 0, "over_amount": 0, "amount_total": 1010000000, "plate_number": "51C-123.45", "pricing_mode": "standard", "sunday_trips": 0, "over_km_price": 0, "toll_included": false, "combo_km_limit": 0, "amount_surcharge": 0, "sunday_surcharge": "0.00", "holiday_surcharge": "0.00", "combo_monthly_price": 0, "standard_price_per_km": "10000.00"}}, "bank_name": "", "has_price": true, "short_name": "25 FIT", "total_toll": 0, "trip_count": 4, "customer_id": 2, "bank_account": "", "company_name": "CHI NHÁNH HÀ NỘI - CÔNG TY CỔ PHẦN 25 FIT", "contact_name": "", "total_amount": 1046465000, "contact_phone": "", "customer_code": "KH002", "confirmed_count": 4}	2026-03-23 09:51:47.524229
10	1	1	Q2	1	1	1000.00	0	40000000	1	t	{"pb_name": "Q2", "tax_code": "0311824673", "total_km": 1000, "vehicles": {"30H21778": {"trips": [{"id": 1, "note": null, "pb_id": 1, "status": "confirmed", "cust_id": 1, "pb_name": "Q2", "rule_id": 1, "capacity": "5.00", "route_to": null, "tax_code": "0311824673", "toll_fee": null, "total_km": "1000.00", "bank_name": null, "driver_id": 1, "extra_fee": "0.00", "is_sunday": false, "trip_code": "CH26030001", "trip_date": "2026-03-21", "created_at": "2026-03-21 16:56:04.294005+07", "created_by": 1, "is_holiday": false, "passengers": null, "route_from": null, "short_name": "SCAN", "updated_at": "2026-03-21 18:41:06.587187+07", "vehicle_id": 5, "approved_at": "2026-03-21 18:38:25.940823+07", "approved_by": 1, "customer_id": 1, "distance_km": null, "driver_name": "NGuyễn Văn lái", "rejected_at": null, "rejected_by": null, "toll_amount": "0.00", "agreed_price": null, "arrival_time": null, "company_name": "CÔNG TY TNHH SCAN GLOBAL LOGISTICS VIỆT NAM", "confirmed_at": "2026-03-21 18:41:06.587187+07", "confirmed_by": 8, "odometer_end": "1110.00", "plate_number": "30H21778", "pricing_mode": "combo", "total_amount": null, "customer_code": "KH001", "customer_note": null, "over_km_price": "10000.00", "price_list_id": null, "toll_included": false, "combo_km_limit": "3000.00", "departure_time": null, "odometer_start": "110.00", "dispatcher_note": null, "pickup_location": "ILSUNG", "cargo_weight_ton": null, "dropoff_location": "ECOTECH", "rejection_reason": null, "sunday_surcharge": "0.00", "toll_receipt_img": null, "cargo_description": null, "holiday_surcharge": "0.00", "bank_account_number": null, "combo_monthly_price": "40000000.00", "primary_contact_name": "DŨNG ĐÀO NGỌC ANH", "waiting_fee_per_hour": "0.00", "customer_confirmed_at": null, "customer_confirmed_by": null, "primary_contact_phone": null, "standard_price_per_km": null}], "over_km": 0, "capacity": "5.00", "has_rule": true, "total_km": 1000, "sunday_km": 0, "total_toll": 0, "trip_count": 1, "amount_base": 40000000, "amount_toll": 0, "over_amount": 0, "amount_total": 40000000, "plate_number": "30H21778", "pricing_mode": "combo", "sunday_trips": 0, "over_km_price": "10000.00", "toll_included": false, "combo_km_limit": "3000.00", "amount_surcharge": 0, "sunday_surcharge": "0.00", "holiday_surcharge": "0.00", "combo_monthly_price": "40000000.00", "standard_price_per_km": 0}}, "bank_name": "", "has_price": true, "short_name": "SCAN", "total_toll": 0, "trip_count": 1, "customer_id": 1, "bank_account": "", "company_name": "CÔNG TY TNHH SCAN GLOBAL LOGISTICS VIỆT NAM", "contact_name": "DŨNG ĐÀO NGỌC ANH", "total_amount": 40000000, "contact_phone": "", "customer_code": "KH001", "confirmed_count": 1}	2026-03-23 09:51:47.524229
\.


--
-- Data for Name: statement_periods; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.statement_periods (id, period_from, period_to, period_label, status, total_amount, total_km, total_trips, customer_count, locked_by, locked_at, created_by, created_at, note) FROM stdin;
2	2026-03-01	2026-03-22	01/03/2026 – 22/03/2026	locked	0	0	0	0	2	2026-03-22 15:02:01.612665	2	2026-03-22 15:02:01.612665	\N
1	2026-02-26	2026-03-25	Kỳ 26/02/2026 – 25/03/2026	locked	1086465000	104431	5	2	2	2026-03-23 09:51:47.524229	2	2026-03-22 14:30:16.501771	\N
\.


--
-- Data for Name: trip_attachments; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.trip_attachments (id, trip_id, file_name, file_path, file_size, mime_type, uploaded_by, uploaded_at) FROM stdin;
\.


--
-- Data for Name: trip_templates; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.trip_templates (id, template_name, route_from, route_to, vehicle_type_id, default_distance, departure_time, note, is_active, created_by, created_at) FROM stdin;
\.


--
-- Data for Name: trips; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.trips (id, trip_code, driver_id, vehicle_id, customer_id, price_list_id, route_from, route_to, trip_date, departure_time, arrival_time, distance_km, cargo_description, cargo_weight_ton, passengers, agreed_price, extra_fee, toll_fee, total_amount, status, customer_confirmed_at, customer_confirmed_by, customer_note, dispatcher_note, created_by, created_at, updated_at, rejection_reason, rejected_at, rejected_by, toll_amount, is_holiday, pickup_location, dropoff_location, odometer_start, odometer_end, toll_receipt_img, confirmed_by, confirmed_at, approved_by, approved_at, note) FROM stdin;
1	CH26030001	1	5	1	\N	\N	\N	2026-03-21	\N	\N	\N	\N	\N	\N	\N	0.00	\N	\N	confirmed	\N	\N	\N	\N	1	2026-03-21 16:56:04.294005+07	2026-03-21 18:41:06.587187+07	\N	\N	\N	0.00	f	ILSUNG	ECOTECH	110.00	1110.00	\N	8	2026-03-21 18:41:06.587187+07	1	2026-03-21 18:38:25.940823+07	\N
2	CH26030002	1	1	2	\N	\N	\N	2026-03-21	\N	\N	\N	\N	\N	\N	\N	0.00	\N	\N	confirmed	\N	\N	\N	\N	3	2026-03-21 19:10:12.787446+07	2026-03-21 19:55:48.065395+07	\N	\N	\N	0.00	f	ILSUNG	ECOTECH	11000.00	112000.00	\N	9	2026-03-21 19:55:48.065395+07	2	2026-03-21 19:11:27.445758+07	\N
3	CH26030003	1	5	2	\N	\N	\N	2026-03-21	\N	\N	\N	\N	\N	\N	\N	0.00	\N	\N	confirmed	\N	\N	\N	\N	2	2026-03-21 20:45:45.516268+07	2026-03-21 20:47:07.871422+07	\N	\N	\N	0.00	f	ILSUNG	ECOTECH	1110.00	2131.00	\N	9	2026-03-21 20:47:07.871422+07	2	2026-03-21 20:46:07.461768+07	\N
4	CH26030004	1	5	2	\N	\N	\N	2026-03-21	\N	\N	\N	\N	\N	\N	\N	0.00	\N	\N	confirmed	\N	\N	\N	\N	2	2026-03-21 20:52:15.15034+07	2026-03-21 20:52:30.450726+07	\N	\N	\N	0.00	f	ILSUNG	ECOTECH	2131.00	3221.00	\N	9	2026-03-21 20:52:30.450726+07	2	2026-03-21 20:52:18.107496+07	\N
5	CH26030005	1	5	2	\N	\N	\N	2026-03-22	\N	\N	\N	\N	\N	\N	\N	0.00	0.00	\N	confirmed	\N	\N	\N	\N	3	2026-03-22 17:35:06.364921+07	2026-03-22 21:59:15.941359+07	chưa chạy đến nơi	2026-03-22 19:07:41.613401+07	9	0.00	f	ILSUNG	ECOTECH	3224.00	3544.00	\N	9	2026-03-22 21:59:15.941359+07	2	2026-03-22 21:58:58.213473+07	
6	CH26030006	1	2	1	\N	\N	\N	2026-03-22	\N	\N	\N	\N	\N	\N	\N	0.00	\N	\N	submitted	\N	\N	\N	\N	3	2026-03-22 22:04:33.907355+07	2026-03-22 22:04:33.907355+07	\N	\N	\N	0.00	f	ILSUNG	ECOTECH	21313.00	31321.00	\N	\N	\N	\N	\N	\N
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.users (id, username, password_hash, full_name, email, phone, role_id, is_active, avatar, created_at, updated_at, employee_code, gender, marital_status, date_of_birth, hire_date, ethnicity, permanent_province, permanent_district, permanent_street, permanent_address, temp_same_as_permanent, temp_province, temp_district, temp_street, temp_address, id_number, id_issue_date, id_issue_place, social_insurance, tax_code, bank_name, bank_account, bank_branch, department_id) FROM stdin;
3	laixe	$2a$06$u6R5h/WucB5Je/dqDe0E9euik6HcKDEzmx5Ul8hWaDoNVsyIiPT82	NGuyễn Văn lái	\N	\N	5	t	\N	2026-03-21 15:42:15.522163+07	2026-03-21 16:03:49.111086+07	NV003	male	\N	\N	\N	Kinh	\N	\N	\N	\N	f	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
2	giamdoc	$2a$06$o4cu7lkZ4dPHhSBO7RCY3ulUxWltEyO4AramfUm2H3A.ALDk6WFJ6	DŨNG ĐÀO NGỌC ANH	dung@dnaexpress.vn	123456	1	t	\N	2026-03-21 15:40:47.596278+07	2026-03-21 16:04:00.143058+07	NV002	male	\N	\N	\N	Kinh	\N	\N	\N	\N	f	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
4	scan	$2a$06$uzVhddNUQjcDqk3UbP9S..Iu3NLChI061wJsXliLS46SpescB.09G	SCAN	dung@dnaexpress.vn	\N	6	t	\N	2026-03-21 16:47:18.805087+07	2026-03-21 16:47:18.805087+07	NV004	\N	\N	\N	\N	Kinh	\N	\N	\N	\N	f	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
5	nam	$2a$06$j/XaVvb/5cga4r0RSaQkHO0Vb8wW5hWS86Q.boBPZdKqks5MSEmeO	nam	\N	\N	6	t	\N	2026-03-21 18:21:13.834737+07	2026-03-21 18:21:27.341788+07	NV005	male	single	\N	\N	\N	\N	\N	\N	\N	f	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
7	dung	$2a$06$FdwWcuiQNEHo9V8csp1KR.6MckrbLa2OCmHLbhFGMNHJfNPbeu/V.	DŨNG ĐÀO NGỌC ANH	dung@dnaexpress.vn	\N	6	t	\N	2026-03-21 18:31:38.155325+07	2026-03-21 18:31:38.155325+07	NV007	\N	\N	\N	\N	Kinh	\N	\N	\N	\N	f	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
6	kh_new	$2a$06$SfmHYdRO4IganhV/QDWXRurN/pV4tYZBWHth66RTBcNXqLjpmfpmK	Nguyễn Khách Hàng	kh_new@example.com	dung	6	t	\N	2026-03-21 18:30:45.183488+07	2026-03-21 18:34:19.497024+07	NV006	\N	\N	\N	\N	Kinh	\N	\N	\N	\N	f	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
8	canduyet	$2y$10$Im6GTXyO1T4jGvm6V3vzAuEOct0.sCLMXIFMLFSapaECvE0h3Kd5m	Nguyễn Văn CAN	\N	\N	6	t	\N	2026-03-21 18:40:33.704995+07	2026-03-21 18:40:33.704995+07	NV008	\N	\N	\N	\N	Kinh	\N	\N	\N	\N	f	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
1	sysadmin	$2a$06$BcIvaMG8hOk8AgJN.bXoNuvki82X8yCuckiain4WIw0IBrx3BcYsi	Quản Trị Hệ Thống	sysadmin@company.com	giamdoc	2	t	\N	2026-03-21 15:34:01.075511+07	2026-03-21 18:43:07.75677+07	NV001	\N	\N	\N	\N	Kinh	\N	\N	\N	\N	f	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
9	fit	$2y$10$tMIaiHKZOh2kFkNW2psyAO2HBS2qEpWjmQziF5bO0XiAUfKGMBevu	nguyễn văn fit	\N	\N	6	t	\N	2026-03-21 19:04:41.259769+07	2026-03-21 19:04:41.259769+07	NV009	\N	\N	\N	\N	Kinh	\N	\N	\N	\N	f	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
10	ketoan	$2a$06$dGcRob4.aDvMn0/e69AHCuTRePlsNeWScuOwIzmnWMgbZhLDM3CnK	KẾ Văn Toán	\N	\N	3	t	\N	2026-03-22 22:08:54.417263+07	2026-03-22 22:08:54.417263+07	NV010	\N	\N	\N	\N	Kinh	\N	\N	\N	\N	f	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
\.


--
-- Data for Name: vehicle_maintenance; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.vehicle_maintenance (id, vehicle_id, maintenance_date, maintenance_type, description, cost, mileage, garage_name, next_maintenance_date, notes, is_driver_fault, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: vehicle_types; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.vehicle_types (id, name, description, created_at, is_active) FROM stdin;
1	Xe tải 1 tấn	Xe tải nhỏ dưới 1 tấn	2026-03-21 15:01:54.136781+07	t
2	Xe tải 2.5 tấn	Xe tải trung	2026-03-21 15:01:54.136781+07	t
3	Xe tải 5 tấn	Xe tải lớn	2026-03-21 15:01:54.136781+07	t
4	Xe tải 10 tấn	Xe tải hạng nặng	2026-03-21 15:01:54.136781+07	t
5	Container 20ft	Xe container 20 feet	2026-03-21 15:01:54.136781+07	t
6	Container 40ft	Xe container 40 feet	2026-03-21 15:01:54.136781+07	t
7	Xe 7 chỗ	Xe khách nhỏ	2026-03-21 15:01:54.136781+07	t
8	Xe 16 chỗ	Xe khách trung	2026-03-21 15:01:54.136781+07	t
9	Xe tải nhẹ	Tải trọng dưới 2.5 tấn	2026-03-21 16:10:59.956804+07	t
10	Xe tải trung	Tải trọng 2.5 - 5 tấn	2026-03-21 16:10:59.956804+07	t
11	Xe tải nặng	Tải trọng trên 5 tấn	2026-03-21 16:10:59.956804+07	t
12	Xe đầu kéo	Xe container, đầu kéo	2026-03-21 16:10:59.956804+07	t
13	Xe bồn	Vận chuyển chất lỏng	2026-03-21 16:10:59.956804+07	t
14	Xe lạnh	Vận chuyển hàng đông lạnh	2026-03-21 16:10:59.956804+07	t
15	Xe ben	Xe tự đổ	2026-03-21 16:10:59.956804+07	t
16	Xe khách	Vận chuyển hành khách	2026-03-21 16:10:59.956804+07	t
\.


--
-- Data for Name: vehicles; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.vehicles (id, plate_number, vehicle_type_id, brand, model, year, capacity_ton, capacity_seat, fuel_type, status, note, created_at, updated_at, fuel_quota, capacity, registration_expiry, insurance_expiry, road_tax_expiry, fire_insurance_expiry, created_by, updated_by, is_active) FROM stdin;
1	51C-123.45	3	Hino	500 Series	2020	5.00	\N	diesel	active	\N	2026-03-21 15:01:54.136781+07	2026-03-21 15:01:54.136781+07	\N	\N	\N	\N	\N	\N	\N	\N	t
2	51C-678.90	5	Isuzu	Giga	2021	18.00	\N	diesel	active	\N	2026-03-21 15:01:54.136781+07	2026-03-21 15:01:54.136781+07	\N	\N	\N	\N	\N	\N	\N	\N	t
3	51D-111.22	7	Toyota	Innova	2022	\N	\N	gasoline	active	\N	2026-03-21 15:01:54.136781+07	2026-03-21 15:01:54.136781+07	\N	\N	\N	\N	\N	\N	\N	\N	t
4	51D-333.44	4	Thaco	Auman	2019	10.00	\N	diesel	active	\N	2026-03-21 15:01:54.136781+07	2026-03-21 15:01:54.136781+07	\N	\N	\N	\N	\N	\N	\N	\N	t
5	30H21778	6	\N	\N	\N	\N	\N	diesel	active	\N	2026-03-21 16:18:36.29317+07	2026-03-21 16:18:36.29317+07	12.00	5.00	\N	\N	\N	\N	1	\N	t
\.


--
-- Data for Name: work_shifts; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.work_shifts (id, shift_code, shift_name, start_time, end_time, late_threshold, break_minutes, work_hours, ot_multiplier, weekend_multiplier, holiday_multiplier, color, is_active, created_by, created_at) FROM stdin;
1	HC	Ca hành chính	08:00:00	17:00:00	15	60	8.00	1.50	2.00	3.00	#0d6efd	t	\N	2026-03-22 20:08:40.878692
2	S1	Ca sáng	06:00:00	14:00:00	10	30	8.00	1.50	2.00	3.00	#198754	t	\N	2026-03-22 20:08:40.878692
3	S2	Ca chiều	14:00:00	22:00:00	10	30	8.00	1.50	2.00	3.00	#fd7e14	t	\N	2026-03-22 20:08:40.878692
4	S3	Ca đêm	22:00:00	06:00:00	10	30	8.00	1.50	2.00	3.00	#6f42c1	t	\N	2026-03-22 20:08:40.878692
\.


--
-- Name: customer_statements_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.customer_statements_id_seq', 1, false);


--
-- Name: customer_users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.customer_users_id_seq', 8, true);


--
-- Name: customers_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.customers_id_seq', 2, true);


--
-- Name: departments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.departments_id_seq', 5, true);


--
-- Name: driver_kpi_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.driver_kpi_id_seq', 1, false);


--
-- Name: driver_ratings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.driver_ratings_id_seq', 1, false);


--
-- Name: drivers_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.drivers_id_seq', 1, true);


--
-- Name: employee_shifts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.employee_shifts_id_seq', 3, true);


--
-- Name: fuel_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.fuel_logs_id_seq', 3, true);


--
-- Name: holidays_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.holidays_id_seq', 10, true);


--
-- Name: hr_attendance_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hr_attendance_id_seq', 6, true);


--
-- Name: hr_leave_balances_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hr_leave_balances_id_seq', 1, false);


--
-- Name: hr_leaves_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hr_leaves_id_seq', 2, true);


--
-- Name: hr_overtime_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hr_overtime_id_seq', 3, true);


--
-- Name: hr_payroll_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hr_payroll_items_id_seq', 1, false);


--
-- Name: hr_payroll_periods_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hr_payroll_periods_id_seq', 1, false);


--
-- Name: hr_salary_configs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hr_salary_configs_id_seq', 1, false);


--
-- Name: kpi_config_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.kpi_config_id_seq', 4, true);


--
-- Name: kpi_scores_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.kpi_scores_id_seq', 2, true);


--
-- Name: maintenance_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.maintenance_logs_id_seq', 3, true);


--
-- Name: notifications_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.notifications_id_seq', 5, true);


--
-- Name: payroll_periods_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.payroll_periods_id_seq', 1, false);


--
-- Name: payroll_slips_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.payroll_slips_id_seq', 1, false);


--
-- Name: permissions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.permissions_id_seq', 50, true);


--
-- Name: price_books_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.price_books_id_seq', 2, true);


--
-- Name: price_lists_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.price_lists_id_seq', 1, false);


--
-- Name: price_rules_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.price_rules_id_seq', 4, true);


--
-- Name: roles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.roles_id_seq', 6, true);


--
-- Name: salary_components_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.salary_components_id_seq', 1, false);


--
-- Name: statement_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.statement_items_id_seq', 10, true);


--
-- Name: statement_periods_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.statement_periods_id_seq', 2, true);


--
-- Name: trip_attachments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.trip_attachments_id_seq', 1, false);


--
-- Name: trip_code_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.trip_code_seq', 1000, false);


--
-- Name: trip_templates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.trip_templates_id_seq', 1, false);


--
-- Name: trips_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.trips_id_seq', 6, true);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.users_id_seq', 10, true);


--
-- Name: vehicle_maintenance_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.vehicle_maintenance_id_seq', 1, true);


--
-- Name: vehicle_types_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.vehicle_types_id_seq', 16, true);


--
-- Name: vehicles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.vehicles_id_seq', 5, true);


--
-- Name: work_shifts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.work_shifts_id_seq', 4, true);


--
-- Name: customer_statements customer_statements_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_statements
    ADD CONSTRAINT customer_statements_pkey PRIMARY KEY (id);


--
-- Name: customer_users customer_users_customer_id_user_id_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_users
    ADD CONSTRAINT customer_users_customer_id_user_id_key UNIQUE (customer_id, user_id);


--
-- Name: customer_users customer_users_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_users
    ADD CONSTRAINT customer_users_pkey PRIMARY KEY (id);


--
-- Name: customers customers_customer_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_customer_code_key UNIQUE (customer_code);


--
-- Name: customers customers_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_pkey PRIMARY KEY (id);


--
-- Name: departments departments_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_pkey PRIMARY KEY (id);


--
-- Name: driver_kpi driver_kpi_driver_id_period_month_period_year_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.driver_kpi
    ADD CONSTRAINT driver_kpi_driver_id_period_month_period_year_key UNIQUE (driver_id, period_month, period_year);


--
-- Name: driver_kpi driver_kpi_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.driver_kpi
    ADD CONSTRAINT driver_kpi_pkey PRIMARY KEY (id);


--
-- Name: driver_ratings driver_ratings_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.driver_ratings
    ADD CONSTRAINT driver_ratings_pkey PRIMARY KEY (id);


--
-- Name: drivers drivers_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.drivers
    ADD CONSTRAINT drivers_pkey PRIMARY KEY (id);


--
-- Name: drivers drivers_user_id_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.drivers
    ADD CONSTRAINT drivers_user_id_key UNIQUE (user_id);


--
-- Name: employee_shifts employee_shifts_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_shifts
    ADD CONSTRAINT employee_shifts_pkey PRIMARY KEY (id);


--
-- Name: fuel_logs fuel_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_logs
    ADD CONSTRAINT fuel_logs_pkey PRIMARY KEY (id);


--
-- Name: holidays holidays_holiday_date_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.holidays
    ADD CONSTRAINT holidays_holiday_date_key UNIQUE (holiday_date);


--
-- Name: holidays holidays_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.holidays
    ADD CONSTRAINT holidays_pkey PRIMARY KEY (id);


--
-- Name: hr_attendance hr_attendance_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_attendance
    ADD CONSTRAINT hr_attendance_pkey PRIMARY KEY (id);


--
-- Name: hr_attendance hr_attendance_user_id_work_date_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_attendance
    ADD CONSTRAINT hr_attendance_user_id_work_date_key UNIQUE (user_id, work_date);


--
-- Name: hr_leave_balances hr_leave_balances_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_leave_balances
    ADD CONSTRAINT hr_leave_balances_pkey PRIMARY KEY (id);


--
-- Name: hr_leave_balances hr_leave_balances_user_id_year_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_leave_balances
    ADD CONSTRAINT hr_leave_balances_user_id_year_key UNIQUE (user_id, year);


--
-- Name: hr_leaves hr_leaves_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_leaves
    ADD CONSTRAINT hr_leaves_pkey PRIMARY KEY (id);


--
-- Name: hr_overtime hr_overtime_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_overtime
    ADD CONSTRAINT hr_overtime_pkey PRIMARY KEY (id);


--
-- Name: hr_payroll_items hr_payroll_items_period_id_user_id_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_payroll_items
    ADD CONSTRAINT hr_payroll_items_period_id_user_id_key UNIQUE (period_id, user_id);


--
-- Name: hr_payroll_items hr_payroll_items_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_payroll_items
    ADD CONSTRAINT hr_payroll_items_pkey PRIMARY KEY (id);


--
-- Name: hr_payroll_periods hr_payroll_periods_period_year_period_month_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_payroll_periods
    ADD CONSTRAINT hr_payroll_periods_period_year_period_month_key UNIQUE (period_year, period_month);


--
-- Name: hr_payroll_periods hr_payroll_periods_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_payroll_periods
    ADD CONSTRAINT hr_payroll_periods_pkey PRIMARY KEY (id);


--
-- Name: hr_salary_configs hr_salary_configs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_salary_configs
    ADD CONSTRAINT hr_salary_configs_pkey PRIMARY KEY (id);


--
-- Name: kpi_config kpi_config_key_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.kpi_config
    ADD CONSTRAINT kpi_config_key_key UNIQUE (key);


--
-- Name: kpi_config kpi_config_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.kpi_config
    ADD CONSTRAINT kpi_config_pkey PRIMARY KEY (id);


--
-- Name: kpi_scores kpi_scores_driver_id_period_from_period_to_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.kpi_scores
    ADD CONSTRAINT kpi_scores_driver_id_period_from_period_to_key UNIQUE (driver_id, period_from, period_to);


--
-- Name: kpi_scores kpi_scores_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.kpi_scores
    ADD CONSTRAINT kpi_scores_pkey PRIMARY KEY (id);


--
-- Name: maintenance_logs maintenance_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.maintenance_logs
    ADD CONSTRAINT maintenance_logs_pkey PRIMARY KEY (id);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: payroll_periods payroll_periods_period_month_period_year_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.payroll_periods
    ADD CONSTRAINT payroll_periods_period_month_period_year_key UNIQUE (period_month, period_year);


--
-- Name: payroll_periods payroll_periods_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.payroll_periods
    ADD CONSTRAINT payroll_periods_pkey PRIMARY KEY (id);


--
-- Name: payroll_slips payroll_slips_period_id_user_id_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.payroll_slips
    ADD CONSTRAINT payroll_slips_period_id_user_id_key UNIQUE (period_id, user_id);


--
-- Name: payroll_slips payroll_slips_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.payroll_slips
    ADD CONSTRAINT payroll_slips_pkey PRIMARY KEY (id);


--
-- Name: permissions permissions_module_action_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_module_action_key UNIQUE (module, action);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: price_books price_books_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_books
    ADD CONSTRAINT price_books_pkey PRIMARY KEY (id);


--
-- Name: price_lists price_lists_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_lists
    ADD CONSTRAINT price_lists_pkey PRIMARY KEY (id);


--
-- Name: price_rules price_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_rules
    ADD CONSTRAINT price_rules_pkey PRIMARY KEY (id);


--
-- Name: price_rules price_rules_price_book_id_vehicle_id_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_rules
    ADD CONSTRAINT price_rules_price_book_id_vehicle_id_key UNIQUE (price_book_id, vehicle_id);


--
-- Name: role_permissions role_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_pkey PRIMARY KEY (role_id, permission_id);


--
-- Name: roles roles_name_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_name_key UNIQUE (name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: salary_components salary_components_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.salary_components
    ADD CONSTRAINT salary_components_pkey PRIMARY KEY (id);


--
-- Name: statement_items statement_items_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.statement_items
    ADD CONSTRAINT statement_items_pkey PRIMARY KEY (id);


--
-- Name: statement_periods statement_periods_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.statement_periods
    ADD CONSTRAINT statement_periods_pkey PRIMARY KEY (id);


--
-- Name: trip_attachments trip_attachments_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trip_attachments
    ADD CONSTRAINT trip_attachments_pkey PRIMARY KEY (id);


--
-- Name: trip_templates trip_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trip_templates
    ADD CONSTRAINT trip_templates_pkey PRIMARY KEY (id);


--
-- Name: trips trips_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trips
    ADD CONSTRAINT trips_pkey PRIMARY KEY (id);


--
-- Name: trips trips_trip_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trips
    ADD CONSTRAINT trips_trip_code_key UNIQUE (trip_code);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_username_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_username_key UNIQUE (username);


--
-- Name: vehicle_maintenance vehicle_maintenance_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_maintenance
    ADD CONSTRAINT vehicle_maintenance_pkey PRIMARY KEY (id);


--
-- Name: vehicle_types vehicle_types_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_types
    ADD CONSTRAINT vehicle_types_pkey PRIMARY KEY (id);


--
-- Name: vehicles vehicles_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicles
    ADD CONSTRAINT vehicles_pkey PRIMARY KEY (id);


--
-- Name: vehicles vehicles_plate_number_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicles
    ADD CONSTRAINT vehicles_plate_number_key UNIQUE (plate_number);


--
-- Name: work_shifts work_shifts_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.work_shifts
    ADD CONSTRAINT work_shifts_pkey PRIMARY KEY (id);


--
-- Name: work_shifts work_shifts_shift_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.work_shifts
    ADD CONSTRAINT work_shifts_shift_code_key UNIQUE (shift_code);


--
-- Name: idx_emp_shifts_user; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_emp_shifts_user ON public.employee_shifts USING btree (user_id, effective_date);


--
-- Name: idx_fuel_logs_approved; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fuel_logs_approved ON public.fuel_logs USING btree (is_approved);


--
-- Name: idx_fuel_logs_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fuel_logs_date ON public.fuel_logs USING btree (log_date);


--
-- Name: idx_fuel_logs_driver; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fuel_logs_driver ON public.fuel_logs USING btree (driver_id);


--
-- Name: idx_fuel_logs_vehicle; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fuel_logs_vehicle ON public.fuel_logs USING btree (vehicle_id);


--
-- Name: idx_hr_att_user_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_hr_att_user_date ON public.hr_attendance USING btree (user_id, work_date);


--
-- Name: idx_hr_attendance_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_hr_attendance_date ON public.hr_attendance USING btree (work_date);


--
-- Name: idx_hr_attendance_user_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_hr_attendance_user_date ON public.hr_attendance USING btree (user_id, work_date);


--
-- Name: idx_hr_leaves_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_hr_leaves_status ON public.hr_leaves USING btree (status);


--
-- Name: idx_hr_leaves_user; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_hr_leaves_user ON public.hr_leaves USING btree (user_id, date_from);


--
-- Name: idx_hr_ot_user_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_hr_ot_user_date ON public.hr_overtime USING btree (user_id, ot_date);


--
-- Name: idx_hr_overtime_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_hr_overtime_status ON public.hr_overtime USING btree (status);


--
-- Name: idx_hr_overtime_user; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_hr_overtime_user ON public.hr_overtime USING btree (user_id, ot_date);


--
-- Name: idx_kpi_driver; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_kpi_driver ON public.driver_kpi USING btree (driver_id, period_year, period_month);


--
-- Name: idx_maintenance_vehicle; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_maintenance_vehicle ON public.maintenance_logs USING btree (vehicle_id);


--
-- Name: idx_notif_user; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_notif_user ON public.notifications USING btree (user_id, is_read);


--
-- Name: idx_si_customer_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_si_customer_id ON public.statement_items USING btree (customer_id);


--
-- Name: idx_si_period_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_si_period_id ON public.statement_items USING btree (period_id);


--
-- Name: idx_statement_items_customer; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_statement_items_customer ON public.statement_items USING btree (customer_id);


--
-- Name: idx_statement_items_period; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_statement_items_period ON public.statement_items USING btree (period_id);


--
-- Name: idx_statement_period_customer; Type: INDEX; Schema: public; Owner: postgres
--

CREATE UNIQUE INDEX idx_statement_period_customer ON public.statement_items USING btree (period_id, customer_id);


--
-- Name: idx_stmt_items_customer; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_stmt_items_customer ON public.statement_items USING btree (customer_id);


--
-- Name: idx_stmt_items_period; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_stmt_items_period ON public.statement_items USING btree (period_id);


--
-- Name: idx_trip_attachments_trip_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_trip_attachments_trip_id ON public.trip_attachments USING btree (trip_id);


--
-- Name: idx_trips_customer; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_trips_customer ON public.trips USING btree (customer_id);


--
-- Name: idx_trips_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_trips_date ON public.trips USING btree (trip_date);


--
-- Name: idx_trips_driver; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_trips_driver ON public.trips USING btree (driver_id);


--
-- Name: idx_trips_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_trips_status ON public.trips USING btree (status);


--
-- Name: idx_trips_vehicle; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_trips_vehicle ON public.trips USING btree (vehicle_id);


--
-- Name: idx_vm_maintenance_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_vm_maintenance_date ON public.vehicle_maintenance USING btree (maintenance_date);


--
-- Name: idx_vm_vehicle_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_vm_vehicle_id ON public.vehicle_maintenance USING btree (vehicle_id);


--
-- Name: customers trg_customer_code; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_customer_code BEFORE INSERT ON public.customers FOR EACH ROW WHEN ((new.customer_code IS NULL)) EXECUTE FUNCTION public.generate_customer_code();


--
-- Name: users trg_employee_code; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_employee_code BEFORE INSERT ON public.users FOR EACH ROW WHEN ((new.employee_code IS NULL)) EXECUTE FUNCTION public.generate_employee_code();


--
-- Name: hr_attendance trg_hr_attendance_updated; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_hr_attendance_updated BEFORE UPDATE ON public.hr_attendance FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: hr_leaves trg_hr_leaves_updated; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_hr_leaves_updated BEFORE UPDATE ON public.hr_leaves FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: hr_overtime trg_hr_overtime_updated; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_hr_overtime_updated BEFORE UPDATE ON public.hr_overtime FOR EACH ROW EXECUTE FUNCTION public.update_updated_at();


--
-- Name: trips trg_trip_code; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_trip_code BEFORE INSERT ON public.trips FOR EACH ROW WHEN ((new.trip_code IS NULL)) EXECUTE FUNCTION public.generate_trip_code();


--
-- Name: customer_statements customer_statements_confirmed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_statements
    ADD CONSTRAINT customer_statements_confirmed_by_fkey FOREIGN KEY (confirmed_by) REFERENCES public.users(id);


--
-- Name: customer_statements customer_statements_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_statements
    ADD CONSTRAINT customer_statements_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: customer_users customer_users_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_users
    ADD CONSTRAINT customer_users_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: customer_users customer_users_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_users
    ADD CONSTRAINT customer_users_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: customers customers_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: customers customers_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: departments departments_manager_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_manager_id_fkey FOREIGN KEY (manager_id) REFERENCES public.users(id);


--
-- Name: driver_kpi driver_kpi_calculated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.driver_kpi
    ADD CONSTRAINT driver_kpi_calculated_by_fkey FOREIGN KEY (calculated_by) REFERENCES public.users(id);


--
-- Name: driver_kpi driver_kpi_driver_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.driver_kpi
    ADD CONSTRAINT driver_kpi_driver_id_fkey FOREIGN KEY (driver_id) REFERENCES public.drivers(id);


--
-- Name: driver_ratings driver_ratings_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.driver_ratings
    ADD CONSTRAINT driver_ratings_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: driver_ratings driver_ratings_driver_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.driver_ratings
    ADD CONSTRAINT driver_ratings_driver_id_fkey FOREIGN KEY (driver_id) REFERENCES public.drivers(id);


--
-- Name: driver_ratings driver_ratings_rated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.driver_ratings
    ADD CONSTRAINT driver_ratings_rated_by_fkey FOREIGN KEY (rated_by) REFERENCES public.users(id);


--
-- Name: driver_ratings driver_ratings_trip_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.driver_ratings
    ADD CONSTRAINT driver_ratings_trip_id_fkey FOREIGN KEY (trip_id) REFERENCES public.trips(id);


--
-- Name: drivers drivers_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.drivers
    ADD CONSTRAINT drivers_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: drivers drivers_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.drivers
    ADD CONSTRAINT drivers_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: employee_shifts employee_shifts_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_shifts
    ADD CONSTRAINT employee_shifts_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: employee_shifts employee_shifts_shift_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_shifts
    ADD CONSTRAINT employee_shifts_shift_id_fkey FOREIGN KEY (shift_id) REFERENCES public.work_shifts(id) ON DELETE CASCADE;


--
-- Name: employee_shifts employee_shifts_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_shifts
    ADD CONSTRAINT employee_shifts_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: fuel_logs fuel_logs_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_logs
    ADD CONSTRAINT fuel_logs_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(id);


--
-- Name: fuel_logs fuel_logs_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_logs
    ADD CONSTRAINT fuel_logs_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: fuel_logs fuel_logs_driver_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_logs
    ADD CONSTRAINT fuel_logs_driver_id_fkey FOREIGN KEY (driver_id) REFERENCES public.drivers(id);


--
-- Name: fuel_logs fuel_logs_vehicle_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_logs
    ADD CONSTRAINT fuel_logs_vehicle_id_fkey FOREIGN KEY (vehicle_id) REFERENCES public.vehicles(id);


--
-- Name: hr_attendance hr_attendance_entered_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_attendance
    ADD CONSTRAINT hr_attendance_entered_by_fkey FOREIGN KEY (entered_by) REFERENCES public.users(id);


--
-- Name: hr_attendance hr_attendance_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_attendance
    ADD CONSTRAINT hr_attendance_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: hr_leave_balances hr_leave_balances_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_leave_balances
    ADD CONSTRAINT hr_leave_balances_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: hr_leaves hr_leaves_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_leaves
    ADD CONSTRAINT hr_leaves_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(id);


--
-- Name: hr_leaves hr_leaves_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_leaves
    ADD CONSTRAINT hr_leaves_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: hr_overtime hr_overtime_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_overtime
    ADD CONSTRAINT hr_overtime_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(id);


--
-- Name: hr_overtime hr_overtime_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_overtime
    ADD CONSTRAINT hr_overtime_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: hr_payroll_items hr_payroll_items_period_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_payroll_items
    ADD CONSTRAINT hr_payroll_items_period_id_fkey FOREIGN KEY (period_id) REFERENCES public.hr_payroll_periods(id);


--
-- Name: hr_payroll_items hr_payroll_items_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_payroll_items
    ADD CONSTRAINT hr_payroll_items_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: hr_payroll_periods hr_payroll_periods_locked_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_payroll_periods
    ADD CONSTRAINT hr_payroll_periods_locked_by_fkey FOREIGN KEY (locked_by) REFERENCES public.users(id);


--
-- Name: hr_salary_configs hr_salary_configs_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hr_salary_configs
    ADD CONSTRAINT hr_salary_configs_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: kpi_scores kpi_scores_calculated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.kpi_scores
    ADD CONSTRAINT kpi_scores_calculated_by_fkey FOREIGN KEY (calculated_by) REFERENCES public.users(id);


--
-- Name: kpi_scores kpi_scores_driver_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.kpi_scores
    ADD CONSTRAINT kpi_scores_driver_id_fkey FOREIGN KEY (driver_id) REFERENCES public.drivers(id);


--
-- Name: maintenance_logs maintenance_logs_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.maintenance_logs
    ADD CONSTRAINT maintenance_logs_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(id);


--
-- Name: maintenance_logs maintenance_logs_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.maintenance_logs
    ADD CONSTRAINT maintenance_logs_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: maintenance_logs maintenance_logs_entered_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.maintenance_logs
    ADD CONSTRAINT maintenance_logs_entered_by_fkey FOREIGN KEY (entered_by) REFERENCES public.users(id);


--
-- Name: maintenance_logs maintenance_logs_vehicle_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.maintenance_logs
    ADD CONSTRAINT maintenance_logs_vehicle_id_fkey FOREIGN KEY (vehicle_id) REFERENCES public.vehicles(id);


--
-- Name: maintenance_logs maintenance_logs_verified_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.maintenance_logs
    ADD CONSTRAINT maintenance_logs_verified_by_fkey FOREIGN KEY (verified_by) REFERENCES public.users(id);


--
-- Name: notifications notifications_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: payroll_periods payroll_periods_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.payroll_periods
    ADD CONSTRAINT payroll_periods_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(id);


--
-- Name: payroll_periods payroll_periods_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.payroll_periods
    ADD CONSTRAINT payroll_periods_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: payroll_slips payroll_slips_period_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.payroll_slips
    ADD CONSTRAINT payroll_slips_period_id_fkey FOREIGN KEY (period_id) REFERENCES public.payroll_periods(id);


--
-- Name: payroll_slips payroll_slips_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.payroll_slips
    ADD CONSTRAINT payroll_slips_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: price_books price_books_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_books
    ADD CONSTRAINT price_books_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: price_books price_books_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_books
    ADD CONSTRAINT price_books_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: price_lists price_lists_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_lists
    ADD CONSTRAINT price_lists_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: price_lists price_lists_vehicle_type_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_lists
    ADD CONSTRAINT price_lists_vehicle_type_id_fkey FOREIGN KEY (vehicle_type_id) REFERENCES public.vehicle_types(id);


--
-- Name: price_rules price_rules_price_book_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_rules
    ADD CONSTRAINT price_rules_price_book_id_fkey FOREIGN KEY (price_book_id) REFERENCES public.price_books(id) ON DELETE CASCADE;


--
-- Name: price_rules price_rules_vehicle_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_rules
    ADD CONSTRAINT price_rules_vehicle_id_fkey FOREIGN KEY (vehicle_id) REFERENCES public.vehicles(id);


--
-- Name: role_permissions role_permissions_permission_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: role_permissions role_permissions_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: salary_components salary_components_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.salary_components
    ADD CONSTRAINT salary_components_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: statement_items statement_items_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.statement_items
    ADD CONSTRAINT statement_items_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id);


--
-- Name: statement_items statement_items_period_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.statement_items
    ADD CONSTRAINT statement_items_period_id_fkey FOREIGN KEY (period_id) REFERENCES public.statement_periods(id) ON DELETE CASCADE;


--
-- Name: statement_periods statement_periods_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.statement_periods
    ADD CONSTRAINT statement_periods_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: statement_periods statement_periods_locked_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.statement_periods
    ADD CONSTRAINT statement_periods_locked_by_fkey FOREIGN KEY (locked_by) REFERENCES public.users(id);


--
-- Name: trip_attachments trip_attachments_trip_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trip_attachments
    ADD CONSTRAINT trip_attachments_trip_id_fkey FOREIGN KEY (trip_id) REFERENCES public.trips(id) ON DELETE CASCADE;


--
-- Name: trip_attachments trip_attachments_uploaded_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trip_attachments
    ADD CONSTRAINT trip_attachments_uploaded_by_fkey FOREIGN KEY (uploaded_by) REFERENCES public.users(id);


--
-- Name: trip_templates trip_templates_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trip_templates
    ADD CONSTRAINT trip_templates_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: trip_templates trip_templates_vehicle_type_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trip_templates
    ADD CONSTRAINT trip_templates_vehicle_type_id_fkey FOREIGN KEY (vehicle_type_id) REFERENCES public.vehicle_types(id);


--
-- Name: trips trips_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trips
    ADD CONSTRAINT trips_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(id);


--
-- Name: trips trips_confirmed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trips
    ADD CONSTRAINT trips_confirmed_by_fkey FOREIGN KEY (confirmed_by) REFERENCES public.users(id);


--
-- Name: trips trips_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trips
    ADD CONSTRAINT trips_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: trips trips_customer_confirmed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trips
    ADD CONSTRAINT trips_customer_confirmed_by_fkey FOREIGN KEY (customer_confirmed_by) REFERENCES public.users(id);


--
-- Name: trips trips_driver_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trips
    ADD CONSTRAINT trips_driver_id_fkey FOREIGN KEY (driver_id) REFERENCES public.drivers(id);


--
-- Name: trips trips_price_list_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trips
    ADD CONSTRAINT trips_price_list_id_fkey FOREIGN KEY (price_list_id) REFERENCES public.price_lists(id);


--
-- Name: trips trips_rejected_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trips
    ADD CONSTRAINT trips_rejected_by_fkey FOREIGN KEY (rejected_by) REFERENCES public.users(id);


--
-- Name: trips trips_vehicle_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.trips
    ADD CONSTRAINT trips_vehicle_id_fkey FOREIGN KEY (vehicle_id) REFERENCES public.vehicles(id);


--
-- Name: users users_department_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_department_id_fkey FOREIGN KEY (department_id) REFERENCES public.departments(id);


--
-- Name: users users_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id);


--
-- Name: vehicle_maintenance vehicle_maintenance_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_maintenance
    ADD CONSTRAINT vehicle_maintenance_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: vehicle_maintenance vehicle_maintenance_vehicle_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_maintenance
    ADD CONSTRAINT vehicle_maintenance_vehicle_id_fkey FOREIGN KEY (vehicle_id) REFERENCES public.vehicles(id) ON DELETE CASCADE;


--
-- Name: vehicles vehicles_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicles
    ADD CONSTRAINT vehicles_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: vehicles vehicles_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicles
    ADD CONSTRAINT vehicles_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: vehicles vehicles_vehicle_type_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicles
    ADD CONSTRAINT vehicles_vehicle_type_id_fkey FOREIGN KEY (vehicle_type_id) REFERENCES public.vehicle_types(id);


--
-- Name: work_shifts work_shifts_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.work_shifts
    ADD CONSTRAINT work_shifts_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- PostgreSQL database dump complete
--

\unrestrict qS03myfsUMGKwFcatYWKL3Z8qg01dxbyaaR3ajZgIKJn5rl0h0TEKBByrqX6Etk

